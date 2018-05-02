<?php

class CurlFtpClientService
{
    /**
    * 通訊協定，只能為 ftp 或 ftps
    * @var  String
    */
    private $protocol = null;

    /**
    * 連線主機 IP 位址
    * @var IP
    */
    private $ip = null;

    /**
    * 連線主機的連接埠
    * @var int
    */
    private $port = null;

    /**
    * 路徑
    * @var string
    */
    private $path = '';

    /**
    * 略過 ssl 檢查
    * @var string
    * 在某些資訊商的 ssl 檔是自簽檔時，會需要設定略過，預設不略過
    */
    private $skip_ssl = false;

    /**
    * 使用者名稱
    * @var string
    */
    private $username = null;

    /**
    * 使用者密碼
    * @var string
    */
    private $password = null;

	/**
	* 儲存設定值
	*/
    private $curl_settings = [];


	/**
	* 建構子
	*/
    public function __construct($ip = null, $protocol = null, $port = null, $skip_ssl = false)
    {
		$this->setHost($ip)
				->setProtocol($protocol, $port)
				->skipSSL($skip_ssl);
    }

	/**
	* 設定 IP
	*/
	public function setHost($ip)
    {
        if (is_null($ip)) {
            throw new Exception ('請輸入IP');
        }
        $this->ip = $ip;
        return $this;
    }

	/**
	* 設定通訊協定以及 port
	*/
	public function setProtocol($protocol = '', $port = null)
    {
        $protocol = strtolower($protocol);
        $protocol = (strlen($protocol) == 0) ? 'ftp' : $protocol;

		if ($protocol == 'ftp' || $protocol == '') {
			$this->protocol = $protocol;
            $this->port = is_null($port) ? 21 : $port;
		} else if ($protocol == 'ftps') {
			$this->protocol = $protocol;
            $this->port = is_null($port) ? 990 : $port;
		} else {
			throw new Exception('本 Client 只收 ftp 或 ftps。');
		}

        return $this;
    }

	/**
    * 設定是否略過 ssl 憑證
    */
	public function skipSSL($is_skip)
	{
		if ($this->protocol == 'ftps') {
			$this->skip_ssl = ($is_skip === true);
		}
		return $this;
	}


	/**
	* 設定帳號密碼
	*/
    public function setAccount($username, $password)
    {
        $this->username = urlencode($username);
        $this->password = urlencode($password);
        return $this;
    }

    /**
    * 設定路徑
    */
    public function chdir($path)
    {
        $this->path = $path;
    }


    public function getScheme()
    {
/*
        $data = [
            'protocol'  => $this->protocol,
            'username'  => $this->username,
            'password'  => $this->password,
            'port'      => $this->port,
            'ip'        => $this->ip
        ];

        $rules = [
            'protocol'  => 'required',
            'username'  => 'required',
            'password'  => 'required',
            'port'      => 'required|integer',
            'ip'        => 'required|ip'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $messages = $validator->messages();
            throw new Exception ($messages);
        }
*/
        $base_scheme = "{$this->username}:{$this->password}@{$this->ip}:{$this->port}";
        $scheme =  "{$this->protocol}://{$this->username}:{$this->password}@{$this->ip}:{$this->port}";

        if (strlen($this->path) > 0) {
            $scheme .= "/{$this->path}";
        }

        return $scheme;
    }

    public function getFileList($path = '')
    {
        try {
            $setting = [
                CURLOPT_FTPLISTONLY => true,
            ];

            $content = $this->setConnection($setting)->fire();
            $contents = trim($contents);
            $contents = explode(PHP_EOL, $contents);

            return $content;
        } catch (Exception $e) {

            // TODO 如果連線失敗，要怎麼處理...
            $error_code = $e->getCode();

        }
    }

    public function download($file, $local_path='./')
    {
        try {
            $fp = fopen($local_path, 'w');

            $setting = [
                CURLOPT_FILE    => $fp,
                CURLOPT_HEADER  => 0
            ];

             $content = $this->setConnection($setting)->fire();
        } catch (Exceptoin $e) {
            // TODO 如果連線失敗，要怎麼處理...
        }
    }

    public function upload($local_path = '', $remote_path)
    {
        try {
            $fp = fopen($local_path, 'r');

            $setting[CURLOPT_INFILE] = $fp;
            $setting[CURLOPT_INFILESIZE] = filesize($local_path);

            $this->setConnection($setting)->fire();
        } catch (Exception $e) {
            // TODO 如果連線失敗，要怎麼處理...s
            $error_code = $e->getCode();
        }
    }

    private function setConnection($params)
    {
        $this->curl_settings = array_merge(
            [
                CURLOPT_URL                 => $this->getScheme($path),
                CURLOPT_CONNECTTIMEOUT      => 90,
                CURLOPT_TIMEOUT             => 90,
                CURLOPT_RETURNTRANSFER      => true,
            ],
            $params
        );

        if ($this->skip_ssl === true) {
            $this->curl_settings[CURLOPT_SSL_VERIFYHOST] = false;
            $this->curl_settings[CURLOPT_SSL_VERIFYPEER] = false;
        }

        return $this;
    }

    private function fire()
    {
        $ch = curl_init();
        curl_setopt_array($ch, $curl_settings);

        $contents = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_errorno = curl_errno($ch);

        if ($curl_errorno !== 0) {
            throw new Exception ('連線失敗', $curl_errorno);
        }

        if ($action == 'get_list') {

        }

        return $contents;
    }
}
