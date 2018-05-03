<?php
// namespace App\Services\Common\Ftp;

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
    private $remote_path = '';

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
    * 等待時間
    * @var int
    */
    private $waiting_seconds = 90;

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
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

    /**
    * 設定等待時間
    */
    public function waiting($seconds)
    {
        $this->waiting_seconds = $seconds;
        return $this;
    }

    /**
    * 切換路徑
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
        // $encoded_username = urlencode($this->username);
        // $encoded_password = urlencode($this->password);
        $scheme =  "{$this->protocol}://{$this->ip}:{$this->port}";

        if (strlen($this->remote_path) > 0) {
            if (preg_match('/^\//', $this->remote_path)) {
                $scheme .= $this->remote_path;
            } else {
                $scheme .= "/{$this->remote_path}";
            }
        }

        return $scheme;
    }

    public function getFileList()
    {
        $setting = [
                CURLOPT_FTPLISTONLY => true,
                CURLOPT_CUSTOMREQUEST => 'LIST'
            ];

        $result = $this->initConnection($setting)->fire();

        if ($result['error_no'] !== 0) {
            $error_constants = $this->transalteErrorNo($result['error_no']);
            throw new Exception("檔案列表取得失敗 ({$error_constants})", $result['error_no']);
        }

        $contents = trim($result['contents']);
        $contents = explode(PHP_EOL, $contents);

        $output = [
            'files' => [],
            'dirs'  => [],
            'detail'    => []
        ];

        $index = 0;
        $pattern = '/([0-9]{2})-([0-9]{2})-([0-9]{2}) +([0-9]{2}:[0-9]{2}[AP]M) +(<DIR>)? + (\d+)? (.*)$/';
        foreach($contents as $val) {
            if (preg_match($pattern, $val, $matches)) {
                $time_str = "{$matches[3]}-{$matches[1]}-{$matches[2]} {$matches[4]}";
                $file_time = date('Y-m-d H:i:s', strtotime($time_str));

                if ($matches[5] == '<DIR>') {
                    $output['dirs'][$index] = $matches[7];

                    $output['detail'][$index] = [
                        'type'  => 'dir',
                        'name'  => $matches[7],
                        'time'  => $file_time
                    ];
                } else {
                    $output['files'][$index] = $matches[7];

                    $output['detail'][$index] = [
                        'type'  => 'file',
                        'name'  => $matches[7],
                        'time'  => $file_time,
                        'size'  => $matches[6]
                    ];
                }
                $index++;
            } else {
                echo "{$val} \n";
            }
        }

        return $output;
    }

    public function download($filename, $local_path='.', $rename='')
    {
        $this->remote_path .= $filename;

        $local_path = preg_replace('/\/+$/', '/', $local_path);
        if (strlen($rename) > 0) {
            $full_path = "$local_path/{$rename}";
        } else {
            $full_path = "$local_path/{$filename}";
        }

        $fp = fopen($full_path, 'w');

        $setting = [
            CURLOPT_FILE    => $fp,
            CURLOPT_HEADER  => 0
        ];

        $file_list = $this->getFileList();

        if (!in_array($filename, $file_list['files'])) {
            throw new Exception("遠端檔案不存在", 0);
        }

        $result = $this->initConnection($setting)->fire();

        if ($result['error_no'] !== 0) {
            $error_constants = $this->transalteErrorNo($result['error_no']);
            throw new Exception("檔案下載失敗 ({$error_constants})", $result['error_no']);
        }

        // 如果檔案不存在，會寫成空檔，遇到空檔案時，就砍了吧。
         if (filesize($full_path) == 0) {
            unlink($full_path);
         }

         return true;
    }

    public function upload($local_path, $remote_filename)
    {
        $this->remote_path .= $remote_filename;
        $fp = fopen($local_path, 'r');

        $setting[CURLOPT_UPLOAD] = true;
        $setting[CURLOPT_INFILE] = $fp;
        $setting[CURLOPT_INFILESIZE] = filesize($local_path);

        $result = $this->initConnection($setting)->fire();
        if ($result['error_no'] !== 0) {
            $error_constants = $this->transalteErrorNo($result['error_no']);
            throw new Exception("檔案上傳失敗 ({$error_constants})", $result['error_no']);
        }

        return true;
    }

    public function delete($remote_filename)
    {
        $this->remote_path .= $remote_filename;
        $setting[CURLOPT_QUOTE] = array("DELE {$this->remote_path}");

        $result = $this->initConnection($setting)->fire();
        if ($result['error_no'] !== 78) {
            $error_constants = $this->transalteErrorNo($result['error_no']);
            throw new Exception("檔案刪除失敗 ({$error_constants})", $result['error_no']);
        }

        return true;
    }

    private function initConnection($params)
    {
        $this->curl_settings = [
            CURLOPT_URL                 => $this->getScheme(),
            CURLOPT_CONNECTTIMEOUT      => $this->waiting_seconds,
            CURLOPT_TIMEOUT             => $this->waiting_seconds,
            CURLOPT_RETURNTRANSFER      => true,
        ];

        foreach($params as $key=>$val) {
            $this->curl_settings[$key] = $val;
        }

        if (!is_null($this->username) && !is_null($this->password)) {
            $this->curl_settings[CURLOPT_USERPWD] = "{$this->username}:{$this->password}";
        }

        if ($this->skip_ssl === true) {
            $this->curl_settings[CURLOPT_SSL_VERIFYHOST] = false;
            $this->curl_settings[CURLOPT_SSL_VERIFYPEER] = false;
        }

        return $this;
    }

    private function fire()
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->curl_settings);

        $contents = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_errorno = curl_errno($ch);

        $result = [
            'contents'  => curl_exec($ch),
            'curl_info' => curl_getinfo($ch),
            'error_no'  => curl_errno($ch)
        ];

        return $result;
    }

    private function transalteErrorNo($error_no){
        // 直接用數字，而不用常數，是因為以前有採過不同PHP版本有不同常數定義的雷...
        $error_table = [
            1 => 'CURLE_UNSUPPORTED_PROTOCOL',
            2 => 'CURLE_FAILED_INIT',
            3 => 'CURLE_URL_MALFORMAT',
            4 => 'CURLE_URL_MALFORMAT_USER',
            5 => 'CURLE_COULDNT_RESOLVE_PROXY',
            6 => 'CURLE_COULDNT_RESOLVE_HOST',
            7 => 'CURLE_COULDNT_CONNECT',
            8 => 'CURLE_FTP_WEIRD_SERVER_REPLY',
            9 => 'CURLE_REMOTE_ACCESS_DENIED',
            11 => 'CURLE_FTP_WEIRD_PASS_REPLY',
            13 => 'CURLE_FTP_WEIRD_PASV_REPLY',
            14 =>'CURLE_FTP_WEIRD_227_FORMAT',
            15 => 'CURLE_FTP_CANT_GET_HOST',
            17 => 'CURLE_FTP_COULDNT_SET_TYPE',
            18 => 'CURLE_PARTIAL_FILE',
            19 => 'CURLE_FTP_COULDNT_RETR_FILE',
            21 => 'CURLE_QUOTE_ERROR',
            22 => 'CURLE_HTTP_RETURNED_ERROR',
            23 => 'CURLE_WRITE_ERROR',
            25 => 'CURLE_UPLOAD_FAILED',
            26 => 'CURLE_READ_ERROR',
            27 => 'CURLE_OUT_OF_MEMORY',
            28 => 'CURLE_OPERATION_TIMEDOUT',
            30 => 'CURLE_FTP_PORT_FAILED',
            31 => 'CURLE_FTP_COULDNT_USE_REST',
            33 => 'CURLE_RANGE_ERROR',
            34 => 'CURLE_HTTP_POST_ERROR',
            35 => 'CURLE_SSL_CONNECT_ERROR',
            36 => 'CURLE_BAD_DOWNLOAD_RESUME',
            37 => 'CURLE_FILE_COULDNT_READ_FILE',
            38 => 'CURLE_LDAP_CANNOT_BIND',
            39 => 'CURLE_LDAP_SEARCH_FAILED',
            41 => 'CURLE_FUNCTION_NOT_FOUND',
            42 => 'CURLE_ABORTED_BY_CALLBACK',
            43 => 'CURLE_BAD_FUNCTION_ARGUMENT',
            45 => 'CURLE_INTERFACE_FAILED',
            47 => 'CURLE_TOO_MANY_REDIRECTS',
            48 => 'CURLE_UNKNOWN_TELNET_OPTION',
            49 => 'CURLE_TELNET_OPTION_SYNTAX',
            51 => 'CURLE_PEER_FAILED_VERIFICATION',
            52 => 'CURLE_GOT_NOTHING',
            53 => 'CURLE_SSL_ENGINE_NOTFOUND',
            54 => 'CURLE_SSL_ENGINE_SETFAILED',
            55 => 'CURLE_SEND_ERROR',
            56 => 'CURLE_RECV_ERROR',
            58 => 'CURLE_SSL_CERTPROBLEM',
            59 => 'CURLE_SSL_CIPHER',
            60 => 'CURLE_SSL_CACERT',
            61 => 'CURLE_BAD_CONTENT_ENCODING',
            62 => 'CURLE_LDAP_INVALID_URL',
            63 => 'CURLE_FILESIZE_EXCEEDED',
            64 => 'CURLE_USE_SSL_FAILED',
            65 => 'CURLE_SEND_FAIL_REWIND',
            66 => 'CURLE_SSL_ENGINE_INITFAILED',
            67 => 'CURLE_LOGIN_DENIED',
            68 => 'CURLE_TFTP_NOTFOUND',
            69 => 'CURLE_TFTP_PERM',
            70 => 'CURLE_REMOTE_DISK_FULL',
            71 => 'CURLE_TFTP_ILLEGAL',
            72 => 'CURLE_TFTP_UNKNOWNID',
            73 => 'CURLE_REMOTE_FILE_EXISTS',
            74 => 'CURLE_TFTP_NOSUCHUSER',
            75 => 'CURLE_CONV_FAILED',
            76 => 'CURLE_CONV_REQD',
            77 => 'CURLE_SSL_CACERT_BADFILE',
            78 => 'CURLE_REMOTE_FILE_NOT_FOUND',
            79 => 'CURLE_SSH',
            80 => 'CURLE_SSL_SHUTDOWN_FAILED',
            81 => 'CURLE_AGAIN',
            82 => 'CURLE_SSL_CRL_BADFILE',
            83 => 'CURLE_SSL_ISSUER_ERROR',
            84 => 'CURLE_FTP_PRET_FAILED',
            84 => 'CURLE_FTP_PRET_FAILED',
            85 => 'CURLE_RTSP_CSEQ_ERROR',
            86 => 'CURLE_RTSP_SESSION_ERROR',
            87 => 'CURLE_FTP_BAD_FILE_LIST',
            88 => 'CURLE_CHUNK_FAILED'
        ];

        if (isset($error_table[$error_no])) {
            return $error_table[$error_no];
        } else {
            return false;
        }
    }
}
