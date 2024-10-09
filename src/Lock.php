<?php

namespace OblioSoftware;

class Lock {
    protected $_path;
    protected $_timeout = 30;
    protected $_lockfileName = '.lockfile';
    protected $_file;

    public function __construct($path, $timeout = 30)
    {
        $this->_path = $path;
        $this->_timeout = $timeout;
        $this->_file = rtrim($this->_path, '/') . '/' . $this->_lockfileName;
        $now = time();
        while (file_exists($this->_file)) {
            if (time() > $now + $timeout) {
                return;
            }
            usleep(100000);
        }
        file_put_contents($this->_file, time());
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close()
    {
        if (file_exists($this->_file)) {
            unlink($this->_file);
        }
    }

    public static function open()
    {
        return new self(WP_OBLIO_DIR);
    } 
}