<?php
require_once('CurlFtpClientService.php');

$objFtpClient = new CurlFtpClientService('220.134.119.194');
$objFtpClient->setAccount('moin', 'sin316');
$objFtpClient->chdir("foo/bar");

var_dump($objFtpClient->getScheme());
