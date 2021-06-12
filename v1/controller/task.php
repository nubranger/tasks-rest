<?php

require_once('db.php');
require_once('../model/Task.php');
require_once('../model/Response.php');

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
    $readDB = DB::connectReadDB();

} catch (PDOException $ex) {
    error_log("Connection error - " . $ex, 0);

    response(
        500,
        false,
        "Database connection error",
        false,
        []
    );
}

if (array_key_exists("taskid", $_GET)) {

    $taskid = $_GET['taskid'];

    if ($taskid == '' || !is_numeric($taskid)) {

        response(
            400,
            false,
            "Task ID cannot be blank or must be numeric",
            false,
            []
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where id = :taskid');

            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                response(
                    404,
                    false,
                    "Task not found",
                    false,
                    []
                );
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completion']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            response(
                200,
                true,
                "",
                true,
                $returnData
            );

        } catch (TaskException $ex) {

            response(
                500,
                false,
                $ex->getMessage(),
                true,
                []
            );

        } catch (PDOException $ex) {
            error_log("Database query error - " . $ex, 0);

            response(
                500,
                false,
                "Failed to get task",
                false,
                []
            );
        }

    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        try {
            $query = $writeDB->prepare('delete from tbltasks where id = :taskid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                response(
                    404,
                    false,
                    "Task not found",
                    false,
                    []
                );
            }

            response(
                200,
                true,
                "Task deleted",
                false,
                []
            );

        } catch (PDOException $ex) {

            response(
                500,
                false,
                "Failed to delete task",
                false,
                []
            );
        }

    }
    else {
        response(
            405,
            false,
            "Request method not allowed",
            false,
            []
        );
    }

}
else {
    response(
        404,
        false,
        "Endpoint not found",
        false,
        []
    );
}