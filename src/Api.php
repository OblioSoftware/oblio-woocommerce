<?php

namespace OblioSoftware;

use Exception;

class Api {
    protected $_cif                 = '';
    protected $_email               = '';
    protected $_secret              = '';
    protected $_accessTokenHandler  = null;
    protected $_baseURL             = 'https://www.oblio.eu';
    
    /**
     *  API constructor
     *  @param string $email - account login email
     *  @param string $secret - find token in: account settings > API secret
     *  @param Api\AccessTokenHandlerInterface $accessTokenHandler (optional)
     */
    public function __construct($email, $secret, $accessTokenHandler = null) {
        $this->_email  = $email;
        $this->_secret = $secret;
        
        if (!$accessTokenHandler) {
            $accessTokenHandler = new Api\AccessTokenHandler();
        }
        if (!$accessTokenHandler instanceof Api\AccessTokenHandlerInterface) {
            throw new \Exception('accessTokenHandler class needs to implement OblioApiAccessTokenHandlerInterface');
        }
        $this->_accessTokenHandler = $accessTokenHandler;
    }
    
    /**
     *  @param array $data - array with the document information (see $defaultData above)
     *  @return array $response
     */
    public function createInvoice($data) {
        return $this->_createDoc('invoice', $data);
    }
    
    /**
     *  @param array $data - array with the document information (see $defaultData above)
     *  @return array $response
     */
    public function createProforma($data) {
        return $this->_createDoc('proforma', $data);
    }
    
    /**
     *  @param array $data - array with the document information (see $defaultData above)
     *  @return array $response
     */
    public function createNotice($data) {
        return $this->_createDoc('notice', $data);
    }
    
    /**
     *  $_cif needs to be set
     *  @param string $type - invoice/notice/proforma/receipt
     *  @param string $seriesName
     *  @param int $number
     *  @return array $response
     */
    public function get($type, $seriesName, $number) {
        $this->_checkType($type);
        $cif = $this->_getCif();
        $request = $this->_getAuthorization();
        $request->get($this->_baseURL . '/api/docs/' . $type, compact('cif', 'seriesName', 'number'));
        $this->_checkErrorResponse($request);
        return json_decode($request->getResponse(), true);
    }
    
    /**
     *  $_cif needs to be set
     *  @param string $type - invoice/notice/proforma/receipt
     *  @param string $seriesName
     *  @param int $number
     *  @param bool $cancel - Cancel(true)/Restore(false)
     *  @return array $response
     */
    public function cancel($type, $seriesName, $number, $cancel = true) {
        $this->_checkType($type);
        $cif = $this->_getCif();
        $request = $this->_getAuthorization();
        $url = $this->_baseURL . '/api/docs/' . $type . '/' . ($cancel ? 'cancel' : 'restore');
        $request->put($url , compact('cif', 'seriesName', 'number'));
        $this->_checkErrorResponse($request);
        return json_decode($request->getResponse(), true);
    }
    
    /**
     *  $_cif needs to be set
     *  @param string $type - invoice/notice/proforma/receipt
     *  @param string $seriesName
     *  @param int $number
     *  @return array $response
     */
    public function delete($type, $seriesName, $number) {
        $this->_checkType($type);
        $cif = $this->_getCif();
        $request = $this->_getAuthorization();
        $request->delete($this->_baseURL . '/api/docs/' . $type, compact('cif', 'seriesName', 'number'));
        $this->_checkErrorResponse($request);
        return json_decode($request->getResponse(), true);
    }
    
    /**
     *  $_cif needs to be set
     *  @param string $type : companies, vat_rates, products, clients, series, languages, management
     *  @param string $name : filter by name
     *  @param array $filters : custom filter
     *  @return array $response
     */
    public function nomenclature($type = null, $name = '', array $filters = []) {
        $cif = '';
        switch ($type) {
            case 'companies':
                break;
            case 'vat_rates':
            case 'products':
            case 'clients':
            case 'series':
            case 'languages':
            case 'management':
                $cif = $this->_getCif();
                break;
            default:
                throw new Exception('Type not implemented');
        }
        $request = $this->_getAuthorization();
        $request->get($this->_baseURL . '/api/nomenclature/' . $type, compact('cif', 'name') + $filters);
        $this->_checkErrorResponse($request);
        return json_decode($request->getResponse(), true);
    }
    
    /**
     * @param string $cif : company cif
     */
    public function setCif($cif) {
        $this->_cif = $cif;
    }
    
    /**
     *  @return object $accessToken
     */
    public function getAccessToken() {
        $accessToken = $this->_accessTokenHandler->get();
        if (!$accessToken) {
            $accessToken = $this->_getAccessToken();
            $this->_accessTokenHandler->set($accessToken);
        }
        return $accessToken;
    }
    
    /** Protected methods */
    
    protected function _createDoc($type, $data) {
        $this->_checkType($type);
        if (empty($data['cif']) && $this->_cif) {
            $data['cif'] = $this->_cif;
        }
        if (empty($data['cif'])) {
            throw new Exception('Empty cif');
        }
        $request = $this->_getAuthorization();
        $request->rawPost($this->_baseURL . '/api/docs/' . $type, json_encode($data));
        $this->_checkErrorResponse($request);
        return json_decode($request->getResponse(), true);
    }
    
    protected function _checkType($type) {
        if (!in_array($type, array('invoice', 'proforma', 'notice', 'receipt'))) {
            throw new Exception('Type not supported');
        }
    }
    
    protected function _getCif() {
        if (!$this->_cif) {
            throw new Exception('Empty cif');
        }
        return $this->_cif;
    }
    
    protected function _getAccessToken() {
        if (!$this->_email || !$this->_secret) {
            throw new Exception('Email or secret are empty!');
        }
        $request = new CurlWrapper();
        $request->addOption(CURLOPT_SSL_VERIFYPEER, false);
        $request->post($this->_baseURL . '/api/authorize/token', array(
            'client_id'     => $this->_email,
            'client_secret' => $this->_secret,
            'grant_type'    => 'client_credentials',
        ));
        $transferInfo = $request->getTransferInfo();
        if ($transferInfo['http_code'] !== 200) {
            throw new Exception(sprintf('Error authorize token! HTTP status: %d', $transferInfo['http_code']), $transferInfo['http_code']);
        }
        $response = $request->getResponse();
        return json_decode($response);
    }
    
    protected function _getAuthorization() {
        $accessToken = $this->getAccessToken();
        $request = new CurlWrapper();
        $request->addOption(CURLOPT_SSL_VERIFYPEER, false);
        $request->addHeader('Authorization', $accessToken->token_type . ' ' . $accessToken->access_token);
        return $request;
    }
    
    protected function _checkErrorResponse($request) {
        $transferInfo = $request->getTransferInfo();
        if ($transferInfo['http_code'] !== 200) {
            $message = json_decode($request->getResponse());
            if (!$message) {
                $message = new \stdClass();
                $message->statusMessage = sprintf('Error! HTTP response status: %d', $transferInfo['http_code']);
            }
            throw new Exception($message->statusMessage, $transferInfo['http_code']);
        }
    }
}