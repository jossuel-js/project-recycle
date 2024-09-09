<?php

function checkLogin() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function isAdmin() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] == 0;
}
