<?php
function checkLogin() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}
