<?php

require_once('../v1/controller/db.php');
require_once('../v1/model/Response.php');

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();

    $response = new Response();
    $response->setHttpStatusCode(200);
    $response->setSuccess(true);
    $response->setMessages("Database Connection OK");
    $response->send();
    exit;
} catch (PDOException $ex) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->setMessages("Database Connection error");
    $response->send();
    exit;
}