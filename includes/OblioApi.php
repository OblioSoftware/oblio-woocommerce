<?php
// https://github.com/svyatov/CurlWrapper/blob/master/CurlWrapper.php
require_once 'CurlWrapper.php';

/**
 *   $defaultData = array(
 *       'cif'                => '',
 *       'client'             => [
 *           'cif'           => '',
 *           'name'          => '',
 *           'rc'            => '',
 *           'code'          => '',
 *           'address'       => '',
 *           'state'         => '',
 *           'city'          => '',
 *           'country'       => '',
 *           'iban'          => '',
 *           'bank'          => '',
 *           'email'         => '',
 *           'phone'         => '',
 *           'contact'       => '',
 *           'vatPayer'      => '',
 *       ],
 *       'issueDate'          => 'yyyy-mm-dd',
 *       'dueDate'            => '',
 *       'deliveryDate'       => '',
 *       'collectDate'        => '',
 *       'seriesName'         => '',
 *       'collect'            => [],
 *       'referenceDocument'  => [],
 *       'language'           => 'RO',
 *       'precision'          => 2,
 *       'currency'           => 'RON',
 *       'products'           => [
 *          [
 *              'name'          => '',
 *              'code'          => '',
 *              'description'   => '',
 *              'price'         => '',
 *              'measuringUnit' => 'buc',
 *              'currency'      => 'RON',
 *              'vatName'       => 'Normala',
 *              'vatPercentage' => 19,
 *              'vatIncluded'   => true,
 *              'quantity'      => 2,
 *          ]
 *       ],
 *       'issuerName'         => '',
 *       'issuerId'           => '',
 *       'noticeNumber'       => '',
 *       'internalNote'       => '',
 *       'deputyName'         => '',
 *       'deputyIdentityCard' => '',
 *       'deputyAuto'         => '',
 *       'selesAgent'         => '',
 *       'mentions'           => '',
 *       'value'              => 0,
 *       'workStation'        => 'Sediu',
 *       'useStock'           => 0,
 *   );
 *   try {
 *       $issuerCif = ''; // your company CIF
 *       $api = new OblioApi($email, $secret);
 *       // create invoice:
 *       $result = $api->createInvoice($defaultData);
 *       
 *       // create proforma:
 *       $result = $api->createProforma($defaultData);
 *       
 *       // create notice:
 *       $result = $api->createNotice($defaultData);
 *   
 *       // get document:
 *       $api->setCif($issuerCif);
 *       $result = $api->get('invoice', $seriesName, $number);
 *   
 *       // cancel/restore document:
 *       $api->setCif($issuerCif);
 *       $result = $api->cancel('invoice', $seriesName, $number, true/false);
 *   
 *       // delete document:
 *       $api->setCif($issuerCif);
 *       $result = $api->delete('invoice', $seriesName, $number);
 *   } catch (Exception $e) {
 *       // error handle
 *   }
 */

class OblioApi {
    protected $_cif                 = '';
    protected $_email               = '';
    protected $_secret              = '';
    protected $_accessTokenHandler  = null;
    protected $_baseURL             = 'https://www.oblio.eu';
    
    /**
     *  API constructor
     *  @param string $email - account login email
     *  @param string $secret - find token in: account settings > API secret
     *  @param OblioApiAccessTokenHandlerInterface $accessTokenHandler (optional)
     */
    public function __construct($email, $secret, $accessTokenHandler = null) {
        $this->_email  = $email;
        $this->_secret = $secret;
        
        if (!$accessTokenHandler) {
            $accessTokenHandler = new OblioApiAccessTokenHandler();
        }
        if (!$accessTokenHandler instanceof OblioApiAccessTokenHandlerInterface) {
            throw new Exception('accessTokenHandler class needs to implement OblioApiAccessTokenHandlerInterface');
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
        $request->addHeader('Authorization', $accessToken->token_type . ' ' . $accessToken->access_token);
        return $request;
    }
    
    protected function _checkErrorResponse($request) {
        $transferInfo = $request->getTransferInfo();
        if ($transferInfo['http_code'] !== 200) {
            $message = json_decode($request->getResponse());
            if (!$message) {
                $message = new stdClass();
                $message->statusMessage = sprintf('Error! HTTP response status: %d', $transferInfo['http_code']);
            }
            throw new Exception($message->statusMessage, $transferInfo['http_code']);
        }
    }
}

// class AccessTokenHandler needs to implement OblioApiAccessTokenHandlerInterface
interface OblioApiAccessTokenHandlerInterface {
    /**
     *  @return stdClass $accessToken
     */
    public function get();
    
    /**
     *  @param stdClass $accessToken
     */
    public function set($accessToken);
}

class OblioApiAccessTokenHandler implements OblioApiAccessTokenHandlerInterface {
    protected $_accessTokenFileHeader   = '<?php die;?>';
    protected $_accessTokenFilePath     = '';
    
    public function __construct($accessTokenFilePath = null) {
        if ($accessTokenFilePath) {
            $this->_accessTokenFilePath = $accessTokenFilePath;
        } else {
            $this->_accessTokenFilePath = realpath(dirname(__FILE__)) . '/access_token.php';
        }
    }
    
    public function get() {
        if (file_exists($this->_accessTokenFilePath)) {
            $accessTokenFileContent = str_replace($this->_accessTokenFileHeader, '', file_get_contents($this->_accessTokenFilePath));
            $accessToken = json_decode($accessTokenFileContent);
            if ($accessToken && $accessToken->request_time + $accessToken->expires_in > time()) {
                return $accessToken;
            }
        }
        return false;
    }
    
    public function set($accessToken) {
        if (!is_string($accessToken)) {
            $accessToken = json_encode($accessToken);
        }
        file_put_contents($this->_accessTokenFilePath, $this->_accessTokenFileHeader . $accessToken);
    }
}