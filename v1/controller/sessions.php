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

if (array_key_exists("sessionid", $_GET)) {

    $sessionid = $_GET['sessionid'];

    if ($sessionid == '' || !is_numeric($sessionid)) {

        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        ($sessionid == '' ? $response->setMessages("Session ID cannot be blank") : false);
        (!is_numeric($sessionid) ? $response->setMessages("Session ID must be numeric") : false);
        $response->send();
        exit;
    }

    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {

        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->setMessages("Access token is missing from the header") : false);
        (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->setMessages("Access token cannot be blank") : false);
        $response->send();
        exit;
    }

    $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        try {
            $query = $writeDB->prepare('delete from tblsessions where id = :sessionid and accesstoken = :accesstoken');
            $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {

                response(
                    400,
                    false,
                    "Failed to log out of this session using access token provided",
                    false,
                    []
                );
            }

            $returnData = [];
            $returnData['session_id'] = intval($sessionid);

            response(
                200,
                true,
                "",
                false,
                $returnData
            );
        } catch (PDOException $ex) {

            response(
                500,
                false,
                "There was an issue logging out - please try again",
                false,
                []
            );
        }
    } else {
        response(
            405,
            false,
            "Request method not allowed",
            false,
            []
        );
    }


} elseif (empty($_GET)) {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        response(
            405,
            false,
            "Request method not allowed",
            false,
            []
        );
    }

    sleep(1);

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

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (!isset($jsonData->username) ? $response->setMessages("Username not supplied") : false);
        (!isset($jsonData->password) ? $response->setMessages("Password not supplied") : false);
        $response->send();
        exit;
    }

    if (strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (strlen($jsonData->username) < 1 ? $response->setMessages("Username cannot be blank") : false);
        (strlen($jsonData->username) > 255 ? $response->setMessages("Username must be less than 255 characters") : false);
        (strlen($jsonData->password) < 1 ? $response->setMessages("Password cannot be blank") : false);
        (strlen($jsonData->password) > 255 ? $response->setMessages("Password must be less than 255 characters") : false);
        $response->send();
        exit;
    }

    try {
        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDB->prepare('SELECT id, fullname, username, password, useractive, loginattempts from tblusers where username = :username');
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {

            response(
                401,
                false,
                "Username or password is incorrect",
                false,
                []
            );
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_username = $row['username'];
        $returned_password = $row['password'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        if ($returned_useractive != 'Y') {

            response(
                401,
                false,
                "User account is not active",
                false,
                []
            );
        }

        if ($returned_loginattempts >= 3) {

            response(
                401,
                false,
                "User account is currently locked out",
                false,
                []
            );
        }

        if (!password_verify($password, $returned_password)) {

            $query = $writeDB->prepare('update tblusers set loginattempts = loginattempts+1 where id = :id');
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            response(
                401,
                false,
                "Username or password is incorrect",
                false,
                []
            );
        }

        // generate access token
        // use 24 random bytes to generate a token then encode this as base64
        // suffix with unix time stamp to guarantee uniqueness (stale tokens)
        $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());

        $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());

        $access_token_expiry_seconds = 1200;
        $refresh_token_expiry_seconds = 1209600;
    } catch (PDOException $ex) {

        response(
            500,
            false,
            "There was an issue logging in - please try again",
            false,
            []
        );
    }

    try {
        $writeDB->beginTransaction();

        $query = $writeDB->prepare('update tblusers set loginattempts = 0 where id = :id');
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $query = $writeDB->prepare('insert into tblsessions (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) values (:userid, :accesstoken, date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), :refreshtoken, date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND))');
        $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
        $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
        $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
        $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
        $query->execute();

        $lastSessionID = $writeDB->lastInsertId();

        $writeDB->commit();

        $returnData = [];
        $returnData['session_id'] = intval($lastSessionID);
        $returnData['access_token'] = $accesstoken;
        $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
        $returnData['refresh_token'] = $refreshtoken;
        $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

        response(
            201,
            true,
            "",
            false,
            $returnData
        );
    } catch (PDOException $ex) {
        $writeDB->rollBack();

        response(
            500,
            false,
            "There was an issue logging in - please try again",
            false,
            []
        );
    }

} else {
    response(
        404,
        false,
        "Endpoint not found",
        false,
        []
    );
}