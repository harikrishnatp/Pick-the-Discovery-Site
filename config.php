<?php

function connectDB($dbname = 'discovery_site_violations'){
    $ts_pw = posix_getpwuid(posix_getuid());
    $credentials = parse_ini_file($ts_pw['dir'] . '/replica.my.cnf');
    $tool_user = $credentials['user'];
    $full_dbname = $tool_user . '__' . $dbname . '_p';

    //connect to ToolsDB
    $db = new mysqli(
        'tools.db.svc.wikimedia.cloud',
        $credentials['user'],
        $credentials['password'],
        $full_dbname
    );

    if ($db->connect_error){
        die(json_encode(['error' => 'Database connection failed: ' . $db->connect_error]));
    }

    $db->set_charset('utf8mb4');

    return $db;
}

function get_request($name, $default = ''){
    return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
}

function getUID($db, $username) {
    $username = $db->real_escape_string($username);
    
    // Look for existing user
    $sql = "SELECT id FROM users WHERE name='$username' LIMIT 1";
    $result = $db->query($sql);
    
    if ($result && $row = $result->fetch_object()) {
        return $row->id;
    }
    
    // Create new user
    $sql = "INSERT INTO users (name) VALUES ('$username')";
    $db->query($sql);
    $uid = $db->insert_id;
    
    // Initialize score record
    $sql = "INSERT INTO scores (user, fixes) VALUES ($uid, 0)";
    $db->query($sql);
    
    return $uid;
}
?>