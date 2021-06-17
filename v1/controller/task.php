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


// BEGIN OF AUTH
// Authenticate user with access token
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

try {
    $query = $writeDB->prepare('select userid, accesstokenexpiry, useractive, loginattempts from tblsessions, tblusers where tblsessions.userid = tblusers.id and accesstoken = :accesstoken');
    $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {

        response(
            401,
            false,
            "Invalid access token",
            false,
            []
        );
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
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

    if (strtotime($returned_accesstokenexpiry) < time()) {

        response(
            401,
            false,
            "Access token has expired",
            false,
            []
        );
    }
} catch (PDOException $ex) {

    response(
        500,
        false,
        "There was an issue authenticating - please try again",
        false,
        []
    );
}
// END OF AUTH SCRIPT

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
            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where id = :taskid and userid = :userid');

            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
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

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        try {
            $query = $writeDB->prepare('delete from tbltasks where id = :taskid and userid = :userid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
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

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

        try {
            if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {

                response(
                    400,
                    false,
                    "Content Type header not set to JSON",
                    false,
                    []
                );
            }

            $rawPatchData = file_get_contents('php://input');

            if (!$jsonData = json_decode($rawPatchData)) {

                response(
                    400,
                    false,
                    "Request body is not valid JSON",
                    false,
                    []
                );
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completion_updated = false;

            $queryFields = "";

            if (isset($jsonData->title)) {
                $title_updated = true;
                $queryFields .= "title = :title, ";
            }

            if (isset($jsonData->description)) {
                $description_updated = true;
                $queryFields .= "description = :description, ";
            }

            if (isset($jsonData->deadline)) {
                $deadline_updated = true;
                $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
            }

            if (isset($jsonData->completion)) {
                $completion_updated = true;
                $queryFields .= "completion = :completion, ";
            }

            $queryFields = rtrim($queryFields, ", ");

            if ($title_updated === false && $description_updated === false && $deadline_updated === false && $completion_updated === false) {

                response(
                    400,
                    false,
                    "No task fields provided",
                    false,
                    []
                );
            }

            $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where id = :taskid and userid = :userid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {

                response(
                    404,
                    false,
                    "No task found to update",
                    false,
                    []
                );
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completion']);
            }

            $queryString = "update tbltasks set " . $queryFields . " where id = :taskid and userid = :userid";
            $query = $writeDB->prepare($queryString);

            if ($title_updated === true) {
                $task->setTitle($jsonData->title);

                $up_title = $task->getTitle();
                $query->bindParam(':title', $up_title, PDO::PARAM_STR);
            }

            if ($description_updated === true) {
                $task->setDescription($jsonData->description);

                $up_description = $task->getDescription();
                $query->bindParam(':description', $up_description, PDO::PARAM_STR);
            }

            if ($deadline_updated === true) {
                $task->setDeadline($jsonData->deadline);

                $up_deadline = $task->getDeadline();
                $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
            }

            if ($completion_updated === true) {
                $task->setCompleted($jsonData->completion);

                $up_completion = $task->getCompleted();
                $query->bindParam(':completion', $up_completion, PDO::PARAM_STR);
            }

            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {

                response(
                    400,
                    false,
                    "Task not updated - given values may be the same as the stored values",
                    false,
                    []
                );
            }

            $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where id = :taskid and userid = :userid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {

                response(
                    404,
                    false,
                    "No task found",
                    false,
                    []
                );
            }
            $taskArray = [];

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
                "Task updated",
                false,
                $returnData
            );
        } catch (TaskException $ex) {

            response(
                400,
                false,
                $ex->getMessage(),
                false,
                []
            );
        } catch (PDOException $ex) {
            error_log("Database Query Error: " . $ex, 0);

            response(
                500,
                false,
                "Failed to update task - check your data for errors",
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

} elseif (array_key_exists("completion", $_GET)) {

    $completion = $_GET['completion'];

    if ($completion !== 'Y' && $completion !== 'N') {

        response(
            400,
            false,
            "Completed filter must be Y or N",
            false,
            []
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where completion = :completion and userid = :userid');
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

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
                false,
                []
            );

        } catch (PDOException $ex) {
            error_log("Database query error - " . $ex, 0);

            response(
                500,
                false,
                "Failed to get tasks",
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
} elseif (array_key_exists("page", $_GET)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $page = $_GET['page'];

        if ($page == '' || !is_numeric($page)) {

            response(
                400,
                false,
                "Page number cannot be blank and must be numeric",
                false,
                []
            );
        }

        $limitPerPage = 3;

        try {
            $query = $readDB->prepare('select count(id) as totalNoOfTasks from tbltasks where userid = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);

            $tasksCount = intval($row['totalNoOfTasks']);

            $numOfPages = ceil($tasksCount / $limitPerPage);

            if ($numOfPages == 0) {
                $numOfPages = 1;
            }

            if ($page > $numOfPages || $page == 0) {

                response(
                    404,
                    false,
                    "Page not found",
                    false,
                    []
                );
            }

            $offset = ($page == 1 ? 0 : ($limitPerPage * ($page - 1)));

            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where userid = :userid limit :pglimit offset :offset');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pglimit', $limitPerPage, PDO::PARAM_INT);
            $query->bindParam(':offset', $offset, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completion']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $tasksCount;
            $returnData['total_pages'] = $numOfPages;
            ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
            ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
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
                false,
                []
            );

        } catch (PDOException $ex) {
            error_log("Database query error - " . $ex, 0);

            response(
                500,
                false,
                "Failed to get tasks",
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

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where userid = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

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
                false,
                []
            );
        } catch (PDOException $ex) {
            error_log("Database query error - " . $ex, 0);

            response(
                500,
                false,
                "Failed to get tasks",
                false,
                []
            );
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

        try {

            if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {

                response(
                    400,
                    false,
                    "Content type header is not set to JSON",
                    false,
                    []
                );
            }

            $rawPOSTData = file_get_contents('php://input');

            if (!$jsonData = json_decode($rawPOSTData)) {

                response(
                    400,
                    false,
                    "Request body is not valid JSON",
                    false,
                    []
                );
            }

            if (!isset($jsonData->title) || !isset($jsonData->completion)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                (!isset($jsonData->title) ? $response->setMessages("Title field is mandatory and must be provided") : false);
                (!isset($jsonData->completion) ? $response->setMessages("Completion field is mandatory and must be provided") : false);
                $response->send();
                exit;
            }

            $newTask = new Task(
                null,
                $jsonData->title,
                (isset($jsonData->description) ? $jsonData->description : null),
                (isset($jsonData->deadline) ? $jsonData->deadline : null),
                $jsonData->completion
            );

            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completion = $newTask->getCompleted();

            $query = $writeDB->prepare('insert into tbltasks (title, description, deadline, completion, userid) values (:title, :description, STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i\'), :completion, :userid)');
            $query->bindParam(':title', $title, PDO::PARAM_STR);
            $query->bindParam(':description', $description, PDO::PARAM_STR);
            $query->bindParam(':deadline', $deadline, PDO::PARAM_STR);
            $query->bindParam(':completion', $completion, PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {

                response(
                    500,
                    false,
                    "Failed to create task",
                    false,
                    []
                );
            }

            $lastTaskID = $writeDB->lastInsertId();

            $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completion from tbltasks where id = :taskid and userid = :userid');
            $query->bindParam(':taskid', $lastTaskID, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {

                response(
                    500,
                    false,
                    "Failed to retrieve task after creation",
                    false,
                    []
                );
            }

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completion']);

                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            response(
                201,
                true,
                "Task created",
                false,
                $returnData
            );

        } catch (TaskException $ex) {
            response(
                400,
                false,
                $ex->getMessage(),
                false,
                []
            );

        } catch (PDOException $ex) {
            error_log("Database query error - " . $ex, 0);

            response(
                500,
                false,
                "Failed to insert task into database - check submitted data for errors",
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
} else {
    response(
        404,
        false,
        "Endpoint not found",
        false,
        []
    );
}