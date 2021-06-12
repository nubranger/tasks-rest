<?php

require_once('../v1/model/Task.php');

try {
    $task = new Task(
        1,
        "Title Here",
        "Description Here",
        "01/01/2019 12:00",
        "N"
    );

    header('Content-type: application/json; charset=UTF-8');
    echo json_encode($task->returnTaskAsArray());
} catch (TaskException $ex) {
    echo "Error: " . $ex->getMessage();
}