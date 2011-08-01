<?php

/*

Guerrilla SMTPd

An SMTP server written in PHP, optimized for receiving email and storing in 
MySQL. Written for GuerrillaMail.com which processes thousands of emails
every hour.

Copyright 2011
Author: Clomode
Contact: flashmob@gmail.com
License: GPL (GNU General Public License, v3)

Why do we need this?

Originally, Guerrilla Mail was running the Exim mail server, and the emails
were fetched using POP.

This proved to be inefficient and unreliable, so the system was replaced in 
favour of piping the emails directly in to a PHP script.

Soon, the piping solution  became a problem too; it required a new process to 
be started for each arriving email, and it also required a new database 
connection every time. 

So, how did we eliminate this bottleneck? Conveniently, PHP has a socket 
library which means we can use PHP to write a simple and efficient SMTP server.
The PHP server runs as a daemon, so it doesn't need to launch a new process for
each incoming email. It also doesn't need to run and insane amount of checks 
for each connection.

We only need to open a single database connection and a single process can be 
re-used indefinitely. The PHP server is able to multiplex simultaneous 
connections, and it can pass the email directly to the MySQL database as soon 
as it is received.

So now we can receive, process and store email all in the one process.
The performance improvement has been dramatic. 

The purpose of this daemon is to grab the email, save it to the database
and disconnect as quickly as possible.

Only simple MIME processing of the email is performed:
- For each email, the header is parsed to determine if the email intended for
the domains serviced. (You wouldn't believe how many spammers do this!)
- For each email, the body of the email is identified, decoded and converted
to UTF-8.

This server does attempt to filter HTML, check for spam or do any sender 
verification. These steps should be performed by other programs.
The server does NOT send any email including bounces. Again, this should
be performed by a separate program.

TO DO: Forking. The parent could fork the worker child and watch the worker.
Then re-start the worker if it dies. Currently, the server is able to
multiplex connextions, so forking is not really necessary.

HOW TO USE / Installation:

- Since the server needs to use port 25, make sure to start as root/admin
- Ensure that your PHP has mb_string and mailparse extensions enabled.
also requires php sockets, iconv
http://php.net/manual/en/book.mailparse.php
http://www.php.net/manual/en/book.mbstring.php
http://www.php.net/manual/en/book.iconv.php
- Modify the 'Configuration' section in this script
- Ensure that no other server is listening on port 25
- Setup your MySQL database (schema below)

- Start on the command line like this:
root@server[] php smtpd.php -l log.txt &
Arguments
-p Specify the port number
-v Verbose output to the console
-l log to this file. If no file specified, will log to ./log.txt

Finally, the server may fail from time to time.
It would need to be checked periodically and re-started if required

// Here is a simple script which can be placed on a cron job:
// (modify for your purposes)
/*
@exec ('ps aux | grep loop', $output, $ret_var);
foreach ($output as $line) {
        if (strpos($line, 'smtpd.php')!==false) {
                $running = true;
                break;
        }
}
if (!$running) {
        @exec ('sh /home/user/startsmtpd');
}
die();


Here is an example of /home/user/startsmtpd

#!/bin/bash
php /home/user/smtpd.php -l /home/user/log.txt -v &

Save the 2 lines above in to a file named startsmtpd
and then do: chmod 755 startsmtpd

Database Schema:

CREATE TABLE IF NOT EXISTS `new_mail` (
  `mail_id` int(11) NOT NULL auto_increment,
  `date` datetime NOT NULL,
  `from` varchar(128) NOT NULL,
  `to` varchar(128) NOT NULL,
  `subject` varchar(255) character set utf8 NOT NULL,
  `body` text character set utf8 NOT NULL,
  `charset` varchar(32) NOT NULL,
  `mail` text NOT NULL,
  `spam_score` float NOT NULL,
  `hash` char(32) NOT NULL,
  `content_type` varchar(64) NOT NULL,
  `recipient` varchar(128) NOT NULL,
  PRIMARY KEY  (`mail_id`),
  KEY `to` (`to`),
  KEY `hash` (`hash`),
  KEY `date` (`date`)
) ENGINE=InnoDB  DEFAULT CHARSET=UTF-8;


Special Thanks to the authors of these pages:
http://devzone.zend.com/article/1086
http://www.greenend.org.uk/rjk/2000/05/21/smtp-replies.html
http://pleac.sourceforge.net/pleac_php/processmanagementetc.html
http://www.tuxradar.com/practicalphp/16/1/6
http://www.freesoft.org/CIE/RFC/1123/92.htm

*/
// typically, this value should be set in php.ini, PHP may ignore it here!
ini_set('memory_limit', '128M'); 

// needed for trapping unix signals
declare (ticks = 1); 

// It's a daemon! We should not exit... A warning though:
// PHP does have memory leaks and you may need to have another script to
// watch your daemon process and restart of needed.
set_time_limit(0); 

// Register a shutdown function when we exit
register_shutdown_function('smtp_shutdown'); // shutdown sockets after a normal shutdown procedure

// You can costomize this
set_error_handler("error_handler");

// install a signal handler
pcntl_signal(SIGCHLD, "signal_handler"); 

// Process arguments
if (isset($argc) && ($argc > 1)) {
    foreach ($argv as $i => $arg) {
        if ($arg == '-p') {
            $listen_port = (int)$argv[$i + 1];
        }
        if ($arg == '-l') {
            $log_file = $argv[$i + 1];
        }
        if ($arg == '-v') {
            $verbose = true;
        }
    }
}
if (!isset($listen_port)) {
    $listen_port = 25;
}
if (isset($log_file)) { 
  
    if (!file_exists($log_file) && file_exists(dirname(__FILE__).'/'.$log_file)) {
        $log_file = dirname(__FILE__).'/'.$log_file;
    } else {
        $log_file = dirname(__FILE__).'/log.txt';
    } 
} else {
    
    echo "log file not specified[]\n";
    $log_file = false;
}
if (!isset($verbose)) {
    
    $verbose = false;
}

##############################################################
# Configuration start
##############################################################
define('MAX_SMTP_CLIENTS', 100);
define('SMTP_HOST_NAME', 'guerrillamail.com');
define('GSMTP_LOG_FILE', $log_file);
define('GSMTP_VERBOSE', $verbose);

define('MYSQL_HOST', 'localhost');
define('MYSQL_USER', 'gmail_mail');
define('MYSQL_PASS', 'y/b9rg26D=9A');
define('MYSQL_DB', 'gmail_mail');

define('GM_MAIL_TABLE', 'new_mail'); // MySQL table for storage

define('GM_PRIMARY_MAIL_HOST', 'guerrillamailblock.com'); // The primary domain name of you email.

// Allowed hosts, a list of domains accepted by this server. Comma dilimited, do not include spaces
define('GM_ALLOWED_HOSTS',
    'guerrillamailblock.com,guerrillamail.com,guerrillamail.net,guerrillamail.biz,guerrillamail.org,sharklasers.com');
define('FORWARD_GMAIL_TO', 'flashmob@gmail.com');
define('GMAIL_EMAIL', 'webmaster@sharklasers.com');

##############################################################
# Configuration end
##############################################################

$jb_mysql_link = mysql_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS) or $DB_ERROR =
    "Couldn't connect to server.";
mysql_select_db(MYSQL_DB, $jb_mysql_link) or $DB_ERROR =
    "Couldn't select database.";
mysql_query("SET NAMES utf8");


log_line($DB_ERROR, 1);

/**
 * error_handler()
 * 
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 * @param array $errcontext
 * @return
 */
function error_handler($errno, $errstr, $errfile, $errline, $errcontext)
{


}


/**
 * signal_handler()
 * This function will be used in the future when the daemon supports forking
 * @param mixed $signal
 * @return
 */
function signal_handler($signal) {
    global $master_socket;
    switch ($signal) {
        case SIGCHLD:
            while (pcntl_waitpid(0, $status) != -1) {
                $status = pcntl_wexitstatus($status);
                log_line("SIGCHILD caught: Child $status completed", 1);
            }
            break;
        case SIGTERM:
            global $clients;
            foreach ($clients as $k => $v) {
                if (is_rsource($v['soket'])) {
                    socket_shutdown($v['soket'], 2);
                    socket_close($v['soket']);
                }

            }
            log_line("SIGTERM caught: exiting", 1);
            socket_shutdown($master_socket, 2);
            socket_close($master_socket);
            exit;

    }
}
// 
/**
 * smtp_shutdown()
 * This is our shutdown function, in
 * here we can do any last operations
 * before the script is complete.
 * Called by the system before the program exits.
 * Do not need to call this function directly.
 * @return
 */
function smtp_shutdown() {
    
    global $clients;
    foreach ($clients as $client_id => $val) {
        if (is_resource($sock)) {
            close_client($clients[$client_id]['socket'], '');
        }
    }
}


/**
 * read_line()
 * Returns a data from the buffer only if the buffer is ready. Clears the
 * buffer before returning, and sets the 'read_buffer_ready' to false
 * @param array $clients
 * @param int $client_id
 * @return string, or false if no data was present in the buffer
 */
function read_line(&$clients, $client_id) {


    if ($clients[$client_id]['read_buffer_ready']) {
        // clear the buffer and return the data
        $buf = $clients[$client_id]['read_buffer'];
        $clients[$client_id]['read_buffer'] = '';
        $clients[$client_id]['read_buffer_ready'] = false;
        return $buf;
    }
    return false;

}


/**
 * close_client()
 * Close a socket. Attempt to write a message before closing
 * @param resource $sock
 * @param string $msg
 * @return
 */
function close_client(&$sock, $msg = "221 Bye\r\n")
{
    if (strlen($msg) > 0) {
        if (substr($msg, -2) !== "\r\n") {
            $msg .= "\r\n";
        }
        socket_write($sock, $msg);
    }
    socket_shutdown($sock, 2);
    socket_close($sock);
}

/**
 * log_line()
 * Log a line of text. If -v argument was passed, level 1 messages
 * will be echoed to the console. Level 2 messages are always logged.
 * @param string $l
 * @param integer $log_level
 * @return
 */
function log_line($l, $log_level = 2)
{
    $l = trim($l);
    if (!strlen($l)) return false;
    if (($log_level == 1) && (GSMTP_VERBOSE)) {
        echo $l . "\n";
    }
    if (GSMTP_LOG_FILE) {
        $fp = fopen(GSMTP_LOG_FILE, 'a');
        fwrite($fp, $l . "\n", strlen($l) + 1);
        fclose($fp);
    }
}

###############################################################
# Guerrilla SMTPd, Main

// Create a TCP Stream socket
/*
$master_sock = socket_create(AF_INET, SOCK_STREAM, 0);
// Bind the socket to an address/port
socket_bind($master_sock, $address, $listen_port) or die('Could not bind to address');
socket_listen($master_sock);
*/

$clients = array();
while (true) {
    // Loop until we acquire a socket
    $master_socket = socket_create_listen($listen_port);
    if ($master_socket === false) {
        log_line("Could not acquire the port, will try again: " . socket_strerror(socket_last_error
            ()), 1);
        sleep(5);
    } else {
        break;
    }
}
$next_id = 1;
log_line("Guerrilla Mail Daemon started on port " . $listen_port, 1);

// This is in a for loop so that future versions of this deamon can be forked.

for (;; ) {
    $newpid = false;
    //$newpid = pcntl_fork(); // TO DO
    if ($newpid === -1) {
        die("Couldn't fork()!");
    } else
        if (!$newpid) {
            // the child
            //posix_setsid();
            /* TO DO Accept incoming requests and handle them as child processes */
           
            $client_count = 0;
            while (is_resource($master_socket)) {

                // is_resource $master_socket
                if ($client_count < MAX_SMTP_CLIENTS) {
                    $read[0] = $master_socket; // socket to poll for accepting new connections
                }
                ###################################
                # READ from the sockets or accept new connections
                $N = null;
                if (!empty($read)) {
 
                    $ready = socket_select($read, $N, $N, null); // are there any sockets need reading?
                    if ($ready) {
                        if (in_array($master_socket, $read)) { // new connection?
                            $new_client = socket_accept($master_socket);
                            if ($new_client !== false) {
                                $client_count++;

                                $clients[$next_id]['socket'] = $new_client; // new socket
                                $clients[$next_id]['state'] = 0;
                                $clients[$next_id]['mail_from'] = '';
                                $clients[$next_id]['rcpt_to'] = '';
                                $clients[$next_id]['error_c'] = 0;
                                $clients[$next_id]['read_buffer'] = '';
                                $clients[$next_id]['read_buffer_ready'] = false; // true if the buffer is ready to be fetched
                                $clients[$next_id]['write_buffer'] = '';
                                $clients[$next_id]['response'] = ''; // response messages are placed here, before they go on the write buffer
                                $clients[$next_id]['time'] = time();
                                $address = '';
                                $port = '';
                                socket_getpeername($clients[$next_id]['socket'], $address, $port);
                                $clients[$next_id]['address'] = $address;

                                $next_id++;
                                log_line('Accepted a new client[' . $next_id . '] (' . $address . ':' . $port .
                                    ')' . " There are $client_count clients(" . sizeof($clients) . ")", 1);
                            }

                        }
                       
                        unset($read[0]); // remove the master socket, we do not read it
                       

                        # Check each soocket and read from it
                        foreach ($read as $client_id => $sock) {
                            if ($listen_port == 2525) {
                                // For debugging, only when running under port 2525
                                echo "[$client_id]omn nom nom (" . strlen($clients[$client_id]['read_buffer']) .
                                    ")\r\n";
                            }

                            $buff = socket_read($sock, 1024);
                            while (true) {
                                if ($buff === '') {
                                    // no more to read
                                    
                                    if (($clients[$client_id]['time'] + 10) < time()) {
                                        log_line("[$client_id] Timed Out! state:" . $clients[$client_id]['state'], 1);
                                        // nothing read for over 10 sec, TIMEOUT!
                                        kill_client($client_id, $clients, $read, '421 ' . SMTP_HOST_NAME .
                                            ': SMTP command timeout - closing connection');
                                    }
                                    break;
                                } elseif ($buff === false) {
                                    // error
                                    log_line('[' . $client_id . ']failed to read from:' . socket_strerror(socket_last_error
                                        ($sock)));
                                    kill_client($client_id, $clients, $read);
                                    
                                    break;
                                } else {
                                    // Read the data in to the read buffer
                                    
                                    $clients[$client_id]['time'] = time();
                                    $clients[$client_id]['read_buffer'] .= $buff;
                                    
                                    // Determine if the buffer is ready
                                    // The are two states when we determine if the buffer is ready.
                                    // State 1 is the command state, when we wait for a command from
                                    // the client
                                    // State 2 is the DATA state, when the client gives is the data
                                    // for the email.

                                    if ($clients[$client_id]['state'] === 1) {
                                        // command state, strings terminate with \r\n
                                        if (strpos($buff, "\r\n", strlen($buff) - 2) !== false) {
                                            $clients[$client_id]['read_buffer_ready'] = true;
                                        }
                                        
                                    } elseif ($clients[$client_id]['state'] === 2) {
                                        // DATA reading state
                                        // not ready unless you get a \r\n.\r\n at the end
                                        $len = strlen($clients[$client_id]['read_buffer']);
                                        if (($len > 1048576) || (($len > 4) && (strpos($clients[$client_id]['read_buffer'],
                                            "\r\n.\r\n", $len - 5)) !== false)) {
                                            $clients[$client_id]['read_buffer_ready'] = true; // finished
                                            $clients[$client_id]['read_buffer'] = substr($clients[$client_id]['read_buffer'],
                                                0, $len - 5);
                                        }
                                    }

                                    break;

                                }
                            }
                        }
                    } else {
                        // socket select failed for some reason
                        log_line("socket_select() failed, reason: " . socket_strerror(socket_last_error
                            ()), 1);

                    }
                }

                // process timeouts for sockets we didn't read
                foreach ($clients as $client_id => $client) {
                    if (!in_array($clients[$client_id]['socket'], $read)) {
                        // we didn't read any data from this socket
                        if (($clients[$client_id]['time'] + 10) < time()) {
                            log_line("[$client_id] Timed Out! state:" . $clients[$client_id]['state'], 1);
                            // nothing read for over 10 sec, TIMEOUT!
                            kill_client($client_id, $clients, $read, '421 ' . SMTP_HOST_NAME .
                                ': SMTP command timeout - closing connection');

                        }
                    }
                }


                ###################################
                # Process the protocol state

                $read = array();

                foreach ($clients as $client_id => $client) {

                    if (is_resource($clients[$client_id]['socket'])) {
                        // place the socket on the reading list
                        $read[$client_id] = $clients[$client_id]['socket']; // we want to read this socket
                    } else {
                        kill_client($client_id, $clients, $read, '');
                        continue; // skip this loop, go to the next client
                    }

                    $input = '';
                    switch ($clients[$client_id]['state']) {
                        case 0:
                            $clients[$client_id]['response'] = '220 ' . $host_name . ' SMTP Service at ' .
                                date('r');
                            $clients[$client_id]['state'] = 1;

                            break;
                        case 1:

                            $input = read_line($clients, $client_id);
                            if ($input)
                                log_line('[' . $client_id . '] cmd:' . $input);
                            if ($input) {

                                if (strpos($input, 'HELO') !== false) {
                                    $temp = explode(' ', $input);
                                    $clients[$client_id]['response'] = '250 Hello ' . trim($temp[1]) .
                                        ', I am glad to meet you';
                                } elseif (strpos($input, 'EHLO') !== false) {
                                    $temp = explode(' ', $input);
                                    $address = '';
                                    $port = '';
                                    socket_getpeername($clients[$client_id]['socket'], $address, $port);
                                    $clients[$client_id]['response'] = '250-' . SMTP_HOST_NAME . ' Hello ' . trim($temp[1]) .
                                        '[' . $address . ']' . "\r\n" . "250-SIZE 131072\r\n" . //"250-PIPELINING\r\n" .
                                        //"250-AUTH PLAIN LOGIN\r\n" .
                                    //"250-STARTTLS\r\n" .
                                    "250 HELP";
                                } elseif (strpos($input, 'MAIL FROM:') !== false) {
                                    $clients[$client_id]['response'] = '250 Ok';
                                } elseif (strpos($input, 'RCPT TO:') !== false) {
                                    if (empty($clients[$client_id]['rcpt_to'])) {
                                        $clients[$client_id]['rcpt_to'] = $input;
                                        $clients[$client_id]['response'] = '250 Accepted';
                                    } else {
                                        kill_client($client_id, $clients, $read,
                                            '550 Requested action not taken: mailbox unavailable');
                                    }

                                    $clients[$client_id]['response'] = '250 Accepted';
                                } elseif (strpos($input, 'DATA') !== false) {
                                    $clients[$client_id]['response'] =
                                        '354 Enter message, ending with "." on a line by itself';
                                    $clients[$client_id]['state'] = 2;
                                    $clients[$client_id]['data_len'] = 0;
                                    $clients[$next_id]['read_buffer'] = '';
                                } elseif (strpos($input, 'QUIT') !== false) {

                                    log_line("client asked to quit", 1);
                                    kill_client($client_id, $clients, $read, '221 Bye');
                                    continue;

                                } elseif (strpos($input, 'NOOP') !== false) {

                                    log_line("client NOOP from client", 1);
                                    unset($read[$client_id]);
                                } else {
                                    log_line('[' . $client_id . ']unrecoginized cmd:' . $input, 1);
                                    $clients[$client_id]['response'] = '500 unrecognized command';
                                    $clients[$client_id]['error_c']++;
                                    if (($clients[$client_id]['error_c'] > 3)) {
                                        kill_client($client_id, $clients, $read, '500 Too many unrecognized commands');
                                        continue;

                                    }
                                }
                            }
                            break;
                        case 2:

                            $input = read_line($clients, $client_id);

                            if ($input) {

                                list($id, $to) = save_email($input);
                                if ($id) {
                                    $clients[$client_id]['response'] = '250 OK : queued as ' . $id;
                                } else {
                                    // The email didn't save properly, usualy because it was in
                                    // an incorrect mime format or bad recipient
                                    $clients[$client_id]['response'] = "554 Transaction failed";
                                }

                                kill_client($client_id, $clients, $read, $clients[$client_id]['response']);


                                log_line("Message for client [$client_id] processed to [$to], told client to exit.",
                                    1);
                                continue;


                            }
                            break;


                    }
                }

                ###################################
                # Write a response

                $write = array(); // sockets we want to write to
                foreach ($clients as $client_id => $client) {
                    // buld a list of sockets that need writing

                    if (!is_resource($client['socket'])) {
                        kill_client($client_id, $clints, $read, '');
                        continue;
                    } elseif (strlen($clients[$client_id]['response']) > 0) {

                        if (substr($clients[$client_id]['response'], -2) !== "\r\n") {
                            $clients[$client_id]['response'] .= "\r\n";
                        }
                        // append the response to the end of the buffer
                        $clients[$client_id]['write_buffer'] .= $clients[$client_id]['response'];
                        $clients[$client_id]['response'] = '';

                    }
                    if ($clients[$client_id]['write_buffer']) {
                        // place this socket on the check-list for socket_select()
                        $write[$client_id] = $client['socket'];
                    }
                }
                if (!empty($write)) {

                    $ready = socket_select($N, $write, $N, null); // are there any sockets need writing?
                    if ($ready) {
                        foreach ($write as $client_id => $sock) {
                            /*
                            If you read/write to a socket, be aware that they do not necessarily read/write 
                            the full amount of data you have requested. 
                            Be prepared to even only be able to read/write a single byte.
                            */
                            $len = socket_write($sock, $clients[$client_id]['write_buffer'], 
                                                    strlen($clients[$client_id]['write_buffer'])); // we have bufffered a response?
                            
                            if ($len) {
                                $address = '';
                                $port = '';
                                socket_getpeername($sock, $address, $port);
                                log_line('[' . $client_id . ']' . $address . ':' . $port . '=>' . $clients[$client_id]['write_buffer']);
                                // remove form the buffer the number of characters written out
                                $clients[$client_id]['write_buffer'] = substr($clients[$client_id]['write_buffer'],
                                    $len);


                            } elseif ($len === false) {
                                log_line('[' . $client_id . ']Failed to write to ' . $clients[$client_id]['address'] .
                                    ':' . socket_strerror(socket_last_error()), 1);
                                kill_client($client_id, $clients, $read, '');
                            }
                        }
                    }
                }


            } // end while
            // Close the client (child) socket
            
            if (is_resource($master_socket)) {
                socket_shutdown($master_socket, 2);
                socket_close($master_socket);
            }
            exit();
        }
}



/**
 * kill_client()
 * Close the socket and remove the client from the list. Attempt to
 * send a message before closing the socket (if the socket is a resource)
 * @param int $client_id
 * @param array $clients
 * @param array $read
 * @param string $msg
 * @return
 */
function kill_client($client_id, &$clients, &$read, $msg = null)
{
    global $client_count;
    if (isset($clients[$client_id])) {

        if (is_resource($clients[$client_id]['socket'])) {
            close_client($clients[$client_id]['socket'], $msg);
            $client_count--;
            log_line("client killed [".$clients[$client_id]['address']."]", 1);
        }
        unset($clients[$client_id]);

        unset($read[$client_id]);

    }


}
#########################################################################################
# Mail Parsing and storage to MySQL

/**
 * mail_body_decode()
 * Decode the mail body to binary. Then convert to UTF-8 if not already
 * @param string $str string to decode
 * @param string $encoding_type eg. 'quoted-printable' or 'base64'
 * @param string $charset and of the charsets supported by iconv()
 * @return string decoded message in a string of UTF-8
 */
function mail_body_decode($str, $encoding_type, $charset = 'UTF-8')
{

    if ($encoding_type == 'base64') {
        $str = base64_decode($str);
    } elseif ($encoding_type == 'quoted-printable') {
        $str = quoted_printable_decode($str);
    }

    if (strtoupper($charset) != 'UTF-8') {
        $str = @iconv(strtoupper($charset), 'UTF-8', $str);
    }
    return $str;


}

/**
 * extract_email()
 * Extract an email address from a header string
 * @param string $str
 * @return string email address, false if none found
 */
function extract_email($str)
{
    static $allowed_hosts;
    if (!$allowed_hosts) {
        $allowed_hosts = explode(',', GM_ALLOWED_HOSTS);
    }

    $arr = imap_rfc822_parse_adrlist($str, GM_PRIMARY_MAIL_HOST);

    foreach ($arr as $item) {

        if (in_array(strtolower($item->host), $allowed_hosts)) {
            return strtolower($item->mailbox . '@' . $item->host);
        }
    }
    return false;

}

/**
 * extract_from_email()
 * See extract_email
 * @param string $str
 * @return string
 */
function extract_from_email($str)
{

    $arr = imap_rfc822_parse_adrlist($str, GM_PRIMARY_MAIL_HOST);
    foreach ($arr as $item) {
        return strtolower($item->mailbox . '@' . $item->host);
    }
    return false;

}


/**
 * save_email()
 * Accepts an email received from a client during the DATA command.
 * This email is processed, the recipient host is verified, the body is
 * decoded, then saved to the database.
 * 
 * @param string $email
 * @return array, with the following elements array($hash, $recipient)
 * where the $hash is a unique id for this email.
 */
function save_email($email)
{

    global $listen_port;
    $mimemail = null;
    $spam_score = '';

    $mimemail = mailparse_msg_create(); // be sure to free this for each email to avoid memory leaks
    if ($listen_port == 2525) {
        // we use port 2525 for testing, start with -p 2525 on the command line
        echo $email;
    }

    mailparse_msg_parse($mimemail, $email);
    $struct = mailparse_msg_get_structure($mimemail);
    $parts = array();
    $body = '';
    
    // Find the body of the email, decode it and change to UTF-8
    // If a message has a html and text part, use the html part
    foreach ($struct as $part_id) {

        $part = mailparse_msg_get_part($mimemail, $part_id);
        $parts[$part_id] = mailparse_msg_get_part_data($part);


        $start = $parts[$part_id]['starting-pos-body'];
        $end = $parts[$part_id]['ending-pos-body'];
        if (isset($parts[$part_id]['content-charset'])) {
            $charset = $parts[$part_id]['content-charset'];
        } else {
            if (empty($charset)) {
                $charset = 'ISO-8859-1';
            }
        }
        if (isset($parts[$part_id]['transfer-encoding'])) {
            $transfer_encoding = $parts[$part_id]['transfer-encoding'];
        } else {
            if (empty($transfer_encoding)) {
                $transfer_encoding = '7bit';
            }
        }
        if (isset($parts[$part_id]['content-type'])) {
            $content_type = $parts[$part_id]['content-type'];
        } elseif (empty($content_type)) {
            $content_type = 'text/plain';
        }


        if ($parts[$part_id]['content-type'] == 'text/html') {
            $body = substr($email, $start, $end - $start);
            $body = mail_body_decode($body, $transfer_encoding, $charset);
            $content_type = $parts[$part_id]['content-type'];
            if (trim($body)) {
                break; // exit the foreach - use this one
            }
        } elseif ($parts[$part_id]['content-type'] == 'text/plain') {
            $body = substr($email, $start, $end - $start);
            $body = mail_body_decode($body, $transfer_encoding, $charset);
            $content_type = $parts[$part_id]['content-type'];
            // do not exit, continue loop - maybe there is a html part?
        }
        if (!$body) {
            // last resort, only if body is blank
            // Sometimes the message may not be using MIME
            // We can chop of the header and simply include the rest as the body.
            $body = substr($email, strpos($email, "\r\n\r\n"), strlen($email));
            $body = mail_body_decode($body, $transfer_encoding, $charset);
            $content_type = $content_type;
        }
    }

    $to = extract_email($parts[1]['headers']['to']);
    $recipient = $to;
    $from = extract_from_email($parts[1]['headers']['from']);
    $subject = ($parts[1]['headers']['subject']);
    $date = $parts[1]['headers']['date']; //
    //eg, subject can be: =?ISO-8859-1?Q?T=E9l=E9chargez_une_photo_de_profil_!?=
    if ($listen_port == 2525) {
        // we use port 2525 for testing, start with -p 2525 on the command line
        echo "bo\;l\;lkjfdsay:[" . $body . ']';
    }
    if (is_array($subject)) {
        error_log(var_export($subject, true));
        $subject = array_pop($subject);
    }
    $subject = @iconv_mime_decode($subject, 1, 'UTF-8');



    list($mail_user, $mail_host) = explode('@', $to);
    $GM_ALLOWED_HOSTS = explode(',', GM_ALLOWED_HOSTS);
    // // put the email in....

    if (in_array($mail_host, $GM_ALLOWED_HOSTS) && ($spam_score < 5.1)) {

        $to = $mail_user . '@' . GM_PRIMARY_MAIL_HOST; // change it to the primary host

        if (array_pop(explode('@', $recipient)) !== 'sharklasers.com') {
            
            $user = array_shift(explode('@', $recipient));
            $sql = "SELECT * FROM `gm2_address` WHERE `address_email`='" . $user .
                "@guerrillamailblock.com' ";
            $result = mysql_query($sql);
            if (mysql_num_rows($result) == 0) {
                //die('No such address');
            }
        }

        $hash = md5($to . $from . $subject . $body); // generate an id for the email
        
        mysql_query("Lock tables " . GM_MAIL_TABLE . " write, gm2_setting write");

        $sql = "INSERT INTO " . GM_MAIL_TABLE .
            " (`date`, `to`, `from`, `subject`, `body`, `charset`, `mail`, `spam_score`, `hash`, `content_type`, `recipient` ) VALUES ('" .
            gmdate('Y-m-d H:i:s') . "', '" . mysql_real_escape_string($to) . "', '" .
            mysql_real_escape_string($from) . "', '" . mysql_real_escape_string($subject) .
            "',  '" . mysql_real_escape_string($body) . "', '" . mysql_real_escape_string($charset) .
            "', '" . mysql_real_escape_string($email) . "', '" . mysql_real_escape_string($spam_score) .
            "', '" . mysql_real_escape_string($hash) . "', '" . mysql_real_escape_string($content_type) .
            "', '" . mysql_real_escape_string($recipient) . "') ";

        mysql_query($sql) or log_line(mysql_error());
        $id = mysql_insert_id();
        $sql = "UPDATE gm2_setting SET `setting_value` = `setting_value`+1 WHERE `setting_name`='received_emails' LIMIT 1";
        mysql_query($sql);
        mysql_query("UNLOCK TABLES");

    }
    log_line('save_email() called, to:[' . $recipient . '] ID:' . $id);
    
    mailparse_msg_free($mimemail); // very important or else the server will leak memory
    return array($hash, $recipient);
}


?>