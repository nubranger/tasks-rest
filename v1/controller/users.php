<?php

require_once('db.php');
require_once('../model/response.php');

function response($statusCode, $success, $message, $cache, $data)
{
    $response = new Response();
    $response->setHttpStatusCode($statusCode);
    $response->setSuccess($success);
    $response->setMessages($message);
    $response->setToCache($cache);
    $response->setData($data);
    $response->send();
    exit;
}

try {
    $writeDB = DB::connectWriteDB();

} catch (PDOException $ex) {
    error_log("Connection Error: " . $ex, 0);

    response(
        500,
        false,
        "Database connection error",
        false,
        []
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    response(
        405,
        false,
        "Request method not allowed",
        false,
        []
    );
}

if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {

    response(
        400,
        false,
        "Content Type header not set to JSON",
        false,
        []
    );
}

$rawPostData = file_get_contents('php://input');

if (!$jsonData = json_decode($rawPostData)) {

    response(
        400,
        false,
        "Request body is not valid JSON",
        false,
        []
    );
}

if (!isset($jsonData->fullname) || !isset($jsonData->username) || !isset($jsonData->password)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (!isset($jsonData->fullname) ? $response->setMessages("Full name not supplied") : false);
    (!isset($jsonData->username) ? $response->setMessages("Username not supplied") : false);
    (!isset($jsonData->password) ? $response->setMessages("Password not supplied") : false);
    $response->send();
    exit;
}

if (strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255 || strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 100) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (strlen($jsonData->fullname) < 1 ? $response->setMessages("Full name cannot be blank") : false);
    (strlen($jsonData->fullname) > 255 ? $response->setMessages("Full name cannot be greater than 255 characters") : false);
    (strlen($jsonData->username) < 1 ? $response->setMessages("Username cannot be blank") : false);
    (strlen($jsonData->username) > 255 ? $response->setMessages("Username cannot be greater than 255 characters") : false);
    (strlen($jsonData->password) < 1 ? $response->setMessages("Password cannot be blank") : false);
    (strlen($jsonData->password) > 100 ? $response->setMessages("Password cannot be greater than 100 characters") : false);
    $response->send();
    exit;
}

$fullname = trim($jsonData->fullname);
$username = trim($jsonData->username);
$password = $jsonData->password;

try {
    $query = $writeDB->prepare('SELECT id from tblusers where username = :username');
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount !== 0) {

        response(
            409,
            false,
            "Username already exists",
            false,
            []
        );
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $query = $writeDB->prepare('INSERT into tblusers (fullname, username, password) values (:fullname, :username, :password)');
    $query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {

        response(
            500,
            false,
            "There was an error creating the user account - please try again",
            false,
            []
        );
    }

    $lastUserID = $writeDB->lastInsertId();

    $returnData = [];
    $returnData['user_id'] = $lastUserID;
    $returnData['fullname'] = $fullname;
    $returnData['username'] = $username;

    response(
        201,
        true,
        "User created",
        false,
        $returnData
    );

} catch (PDOException $ex) {

    response(
        500,
        false,
        "There was an issue creating a user account - please try again",
        false,
        []
    );
}