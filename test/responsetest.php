<?php

require_once('../v1/model/Response.php');

$response = new Response();
$response->setSuccess(true);
$response->setHttpStatusCode(200);
$response->setMessages("Test Message 1");
$response->setMessages("Test Message 2");
$response->send();
