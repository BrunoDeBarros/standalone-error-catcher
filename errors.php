<?php

/**
 * HTTP Request
 * A web crawler which behaves just like a regular web browser, interpreting
 * location redirects and storing cookies automatically.
 * LICENSE
 * Copyright (c) 2015 Bruno De Barros <bruno@terraduo.com>
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 * @author     Bruno De Barros <bruno@terraduo.com>
 * @copyright  Copyright (c) 2015 Bruno De Barros <bruno@terraduo.com>
 * @license    http://opensource.org/licenses/mit-license     MIT License
 * @version    1.0.2
 */
class HTTP_Request {

    public $cookies = array();
    public $user_agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) AppleWebKit/536.26.17 (KHTML, like Gecko) Version/6.0.2 Safari/536.26.17';
    public $last_url = '';
    public $multipart = false;
    public $redirections = 0;
    public $max_redirections = 10;
    public $header = array();

    protected $last_request = null;

    function __construct() {

    }

    function getLastRequest() {
        return $this->last_request;
    }

    function request($url, $mode = 'GET', $data = array(), $save_to_file = false) {
        if (!stristr($url, 'http://') and !stristr($url, 'https://')) {
            $url = 'http://' . $url;
        }
        $original = $url;
        $url = parse_url($url);
        if (!isset($url['host'])) {
            print_r($url);
            throw new HTTP_Request_Exception("Failed to parse the given URL correctly.");
        }
        if (!isset($url['path'])) {
            $url['path'] = '/';
        }
        if (!isset($url['query'])) {
            $url['query'] = '';
        }

        if (!isset($url['port'])) {
            $url['port'] = ($url['scheme'] == 'https') ? 443 : 80;
        }

        $errno = 0;
        $errstr = '';
        $port = $url['port'];
        $sslhost = (($url['scheme'] == 'https') ? 'tls://' : '') . $url['host'];
        $fp = @fsockopen($sslhost, $port, $errno, $errstr, 30);
        if (!$fp) {
            throw new HTTP_Request_Exception("Failed to connect to {$url['host']}.");
        } else {
            $url['query'] = '?' . ((empty($url['query']) and $mode == 'GET') ? http_build_query($data) : $url['query']);
            $out = "$mode {$url['path']}{$url['query']} HTTP/1.0\r\n";
            $out .= "Host: {$url['host']}\r\n";
            $out .= "User-Agent: {$this->user_agent}\r\n";
            if (count($this->cookies) > 0) {
                $out .= "Cookie: ";
                $i = 0;
                foreach ($this->cookies as $name => $cookie) {
                    if ($i == 0) {
                        $out .= "$name=$cookie";
                        $i = 1;
                    } else {
                        $out .= "; $name=$cookie";
                    }
                }
                $out .= "\r\n";
            }
            if (!empty($this->last_url)) {
                $out .= "Referer: " . $this->last_url . "\r\n";
            }
            $out .= "Connection: Close\r\n";

            if ($mode == "POST") {
                if (!$this->multipart) {
                    $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
                    $post = self::urlencodeArray($data, $this->multipart);
                } else {
                    $out .= "Content-Type: multipart/form-data; boundary=AaB03x\r\n";
                    $post = self::urlencodeArray($data, $this->multipart, 'AaB03x');
                }
                $out .= "Content-Length: " . strlen($post) . "\r\n";
                $out .= "\r\n";
                $out .= $post;
            } else {
                $out .= "\r\n";
            }

            $content = '';
            $header = '';
            $header_passed = false;

            $this->last_request = $out;

            if (fwrite($fp, $out)) {

                if (stristr($original, '://')) {
                    $this->last_url = $original;
                } else {
                    $this->last_url = "://" . $original;
                }

                if ($save_to_file) {
                    $fh = fopen($save_to_file, 'w+');
                }

                while (!feof($fp)) {
                    if ($header_passed) {
                        $line = fread($fp, 1024);
                    } else {
                        $line = fgets($fp);
                    }

                    if ($line == "\r\n" and !$header_passed) {
                        $header_passed = true;
                        $line = "";

                        $header = self::parseHeaders($header);

                        if (isset($header['Set-Cookie'])) {
                            if (is_array($header['Set-Cookie'])) {
                                foreach ($header['Set-Cookie'] as $cookie) {
                                    $cookie = explode(';', $cookie);
                                    $cookie = explode('=', $cookie[0], 2);
                                    $this->cookies[$cookie[0]] = $cookie[1];
                                }
                            } else {
                                $header['Set-Cookie'] = explode(';', $header['Set-Cookie']);
                                $header['Set-Cookie'] = explode('=', $header['Set-Cookie'][0], 2);
                                $this->cookies[$header['Set-Cookie'][0]] = $header['Set-Cookie'][1];
                            }
                        }

                        $this->header = $header;

                        if (isset($header['Location']) and $this->redirections < $this->max_redirections) {

                            $location = parse_url($header['Location']);
                            $custom_port = ($url['port'] == 80 or $url['port'] == 443) ? '' : ':' . $url['port'];

                            if (!isset($location['host'])) {

                                if (substr($header['Location'], 0, 1) == '/') {
                                    # It's an absolute URL.
                                    $header['Location'] = $url['scheme'] . '://' . $url['host'] . $custom_port . $header['Location'];
                                } else {
                                    # It's a relative URL, let's take care of it.
                                    $path = explode('/', $url['path']);
                                    array_pop($path);
                                    $header['Location'] = $url['scheme'] . '://' . $url['host'] . $custom_port . implode('/', $path) . '/' . $header['Location'];
                                }
                            }
                            $this->redirections++;
                            $content = $this->request($header['Location'], $mode, $data, $save_to_file);
                            break;
                        }
                    }
                    if ($header_passed) {
                        if (!$save_to_file) {
                            $content .= $line;
                        } else {
                            fwrite($fh, $line);
                        }
                    } else {
                        $header .= $line;
                    }
                }
                fclose($fp);
                if ($save_to_file) {
                    fclose($fh);
                }

                return $content;
            } else {
                throw new HTTP_Request_Exception("Failed to send request headers to $url.");
            }
        }
    }

    public static function urlencodeArray($data, $multipart = false, $boundary = '') {
        $return = "";
        $i = 0;

        if ($multipart) {
            $return = '--' . $boundary;

            foreach ($data as $key => $value) {
                $return .= "\r\n" . 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n" . "\r\n";
                $return .= $value . "\r\n";
                $return .= '--' . $boundary;
            }

            $return .= '--';
        } else {
            $return = http_build_query($data);
        }

        return $return;
    }

    public static function GetBetween($content, $start, $end) {
        $r = explode($start, $content, 2);
        if (isset($r[1])) {
            $r = explode($end, $r[1], 2);

            return $r[0];
        }

        return '';
    }

    public static function parseHeaders($headers) {
        $return = array();
        $headers = explode("\r\n", $headers);
        $response = explode(" ", $headers[0]);
        $return['STATUS'] = $response[1];
        unset($headers[0]);
        foreach ($headers as $header) {
            $header = explode(": ", $header, 2);
            if (!isset($return[$header[0]])) {
                if (isset($header[1])) {
                    $return[$header[0]] = $header[1];
                }
            } else {
                if (!is_array($return[$header[0]])) {
                    $return[$header[0]] = array($return[$header[0]]);
                }
                $return[$header[0]][] = $header[1];
            }
        }

        return $return;
    }

}

class HTTP_Request_Exception extends Exception {

}

class Exceptions {

    /**
     * Some nice names for the error types
     */
    public static $php_errors = array(
        E_ERROR => 'Fatal Error',
        E_USER_ERROR => 'User Error',
        E_PARSE => 'Parse Error',
        E_WARNING => 'Warning',
        E_USER_WARNING => 'User Warning',
        E_STRICT => 'Strict',
        E_NOTICE => 'Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
    );

    /**
     * List of available error levels
     *
     * @var array
     * @access public
     */
    public static $static_levels = array(
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parsing Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Runtime Notice',
    );

    /**
     * The Shutdown errors to show (all others will be ignored).
     */
    public static $shutdown_errors = array(E_PARSE, E_ERROR, E_USER_ERROR, E_COMPILE_ERROR);

    /**
     * Construct
     * Sets the error handlers.
     *
     * @access    public
     * @return    void
     */
    public function __construct() {
        //Set the Exception Handler
        set_exception_handler(array('Exceptions', 'exception_handler'));

        // Set the Error Handler
        set_error_handler(array('Exceptions', 'error_handler'));

        // Set the handler for shutdown to catch Parse errors
        register_shutdown_function(array('Exceptions', 'shutdown_handler'));

        // This is a hack to set the default timezone if it isn't set. Not setting it causes issues.
        date_default_timezone_set(date_default_timezone_get());
    }

    /**
     * Error Handler
     * Converts all errors into ErrorExceptions. This handler
     * respects error_reporting settings.
     *
     * @access    public
     * @throws    ErrorException
     * @return    bool
     */
    public static function error_handler($code, $error, $file = null, $line = null) {
        if (error_reporting() & $code) {
            // This error is not suppressed by current error reporting settings
            // Convert the error into an ErrorException
            $severity = (!isset(self::$static_levels[$code])) ? $code : self::$static_levels[$code];
            throw new Exception("PHP $severity - $error ($file:$line)");
        }

        // Do not execute the PHP error handler
        return true;
    }

    /**
     * Exception Handler
     * Displays the error message, source of the exception, and the stack trace of the error.
     *
     * @access    public
     *
     * @param    object     exception object
     *
     * @return    boolean
     */
    public static function exception_handler($e, $display_error = true) {
        global $_CLEAN_SERVER;

        try {
            $is_not_local = ((!defined('ENV') or stristr(ENV, 'local') === false) and (!defined('env') or stristr(env, 'dev') === false) and !isset($_COOKIE['iama_developer']));

            if (php_uname('s') == "Darwin") {
                $is_not_local = false;
            }

            // Get the exception information
            $type = get_class($e);
            $code = $e->getCode();
            $message = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine();

            // Create a text version of the exception
            $error = self::exception_text($e);

            // Get the exception backtrace
            $trace = $e->getTrace();

            if ($e instanceof ErrorException) {
                if (isset(self::$php_errors[$code])) {
                    // Use the human-readable error name
                    $code = self::$php_errors[$code];
                }

            }

            $env_title = defined("ENV_TITLE") ? ENV_TITLE : "Unknown Project";
            $subject = "[" . "$env_title" . "] $message ($file [$line])";

            foreach ($trace as $key => $value) {

                $arg0 = isset($value['args']) ? (isset($value['args'][0]) ? $value['args'][0] : '') : '';
                $arg0 = is_string($arg0) ? $arg0 : '';

                if (!isset($value['file']) or stristr($value['file'], 'core/CodeIgniter.php') or stristr($value['file'], 'core/Loader.php') or stristr($value['file'], 'core/Common.php') or (stristr($arg0, 'core/CodeIgniter.php')) or (stristr($arg0, 'codeigniter_system/base_index.php'))
                ) {
                    unset($trace[$key]);
                }

                $force_logging = isset($trace[$key]['function']) and in_array($trace[$key]['function'], array("log_without_error"));

                if ($is_not_local and !$force_logging) {
                    unset($trace[$key]['args']);
                }
            }

            $reset_trace = reset($trace);

            if ($e instanceof FailedValidationException) {
                array_unshift($trace, array(
                    'file' => $e->getFile(),
                    'line' => $line,
                    'function' => '__construct',
                    'class' => get_class($e),
                    'type' => '->',
                    'args' =>
                        array(
                            'validation_errors' => $e->get_validation_errors(),
                            'data' => $e->get_data(),
                        ),
                ));
            }

            $new_file = $reset_trace['file'];
            $new_line = $reset_trace['line'];

            if ($new_file) {
                $file = $new_file;
                $line = $new_line;
            }

            $_CLEAN_SERVER = $_SERVER;

            $delete = array('HTTP_ACCEPT_ENCODING', 'REDIRECT_STATUS', 'HTTP_DNT', 'HTTP_COOKIE', 'HTTP_CONNECTION', 'PATH', 'SERVER_SIGNATURE', 'SERVER_SOFTWARE', 'SERVER_ADMIN', 'REMOTE_PORT', 'SERVER_PROTOCOL', 'GATEWAY_INTERFACE', 'argv', 'argc', 'REQUEST_TIME_FLOAT', 'REQUEST_TIME');

            foreach ($delete as $var) {
                if (isset($_CLEAN_SERVER[$var])) {
                    unset($_CLEAN_SERVER[$var]);
                }
            }

            $contents = self::error_php_custom($type, $code, $message, $file, $line, $trace);

            if ($display_error or !$is_not_local) {

                if (!headers_sent()) {
                    header_remove("Cache-Control");
                    header("Content-Type: text/html");
                    header_remove("Content-Disposition");
                }

                # Get rid of everything on the page,
                # so the error is the only thing displayed.
                while (@ob_end_clean()) {
                    # Just getting rid of all output.
                }

                if ($is_not_local) {
                    $title = "Unknown Error";
                    $subtitle = "An unknown error has occurred.";
                    $enduser_message = "<p>We track these errors automatically and will resolve them as quickly as possible.</p>
                                        <p>If the problem persists feel free to contact us.</p>";
                    echo self::error_php_enduser($title, $subtitle, $enduser_message);
                } else {
                    echo $contents;
                }

                if ($is_not_local) {
                    exception_send_mail($subject, $contents);
                }

                # Make sure to end the execution of the script now.
                exit(1);
            } else {
                exception_send_mail($subject, $contents);
            }
            return true;
        } catch (Throwable $e) {
            // Clean the output buffer if one exists
            ob_get_level() and ob_clean();

            // Display the exception text
            echo self::exception_text($e), "\n";

            // Exit with an error status
            exit(1);
        } catch (Exception $e) {
            // Clean the output buffer if one exists
            ob_get_level() and ob_clean();

            // Display the exception text
            echo self::exception_text($e), "\n";

            // Exit with an error status
            exit(1);
        }
    }

    /**
     * Shutdown Handler
     * Catches errors that are not caught by the error handler, such as E_PARSE.
     *
     * @access    public
     * @return    void
     */
    public static function shutdown_handler() {
        $error = error_get_last();
        if ($error = error_get_last() AND in_array($error['type'], self::$shutdown_errors)) {
            // Clean the output buffer
            ob_get_level() and ob_clean();

            // Fake an exception for nice debugging
            self::exception_handler(new ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line']));

            // Shutdown now to avoid a "death loop"
            exit(1);
        }
    }

    /**
     * Exception Text
     * Makes a nicer looking, 1 line extension.
     *
     * @access    public
     *
     * @param    object    Exception
     *
     * @return    string
     */
    public static function exception_text($e) {
        return sprintf('%s [ %s ]: %s ~ %s [ %d ]', get_class($e), $e->getCode(), strip_tags($e->getMessage()), $e->getFile(), $e->getLine());
    }

    /**
     * Debug Source
     * Returns an HTML string, highlighting a specific line of a file, with some
     * number of lines padded above and below.
     *
     * @access    public
     *
     * @param    string      file to open
     * @param    integer     line number to highlight
     * @param    integer     number of padding lines
     *
     * @return    string     source of file
     * @return    FALSE     file is unreadable
     */
    public static function debug_source($file, $line_number, $padding = 5) {
        if (!$file OR !is_readable($file)) {
            // Continuing will cause errors
            return false;
        }

        // Open the file and set the line position
        $file = fopen($file, 'r');
        $line = 0;

        // Set the reading range
        $range = array('start' => $line_number - $padding, 'end' => $line_number + $padding);

        // Set the zero-padding amount for line numbers
        $format = '% ' . strlen($range['end']) . 'd';

        $source = '';
        while (($row = fgets($file)) !== false) {
            // Increment the line number
            if (++$line > $range['end'])
                break;

            if ($line >= $range['start']) {
                // Make the row safe for output
                $row = htmlspecialchars($row, ENT_NOQUOTES);

                // Trim whitespace and sanitize the row
                $row = '<span class="number">' . sprintf($format, $line) . '</span> ' . $row;

                if ($line === $line_number) {
                    // Apply highlighting to this row
                    $row = '<span class="line highlight">' . $row . '</span>';
                } else {
                    $row = '<span class="line">' . $row . '</span>';
                }

                // Add to the captured source
                $source .= $row;
            }
        }

        // Close the file
        fclose($file);

        return '<pre class="source"><code>' . $source . '</code></pre>';
    }

    /**
     * Trace
     * Returns an array of HTML strings that represent each step in the backtrace.
     *
     * @access    public
     *
     * @param    string    path to debug
     *
     * @return    string
     */
    public static function trace(array $trace = null) {
        if ($trace === null) {
            // Start a new trace
            $trace = debug_backtrace();
        }

        // Non-standard function calls
        $statements = array('include', 'include_once', 'require', 'require_once');

        $output = array();
        foreach ($trace as $step) {
            if (!isset($step['function'])) {
                // Invalid trace step
                continue;
            }

            if (isset($step['file']) AND isset($step['line'])) {
                // Include the source of this step
                $source = self::debug_source($step['file'], $step['line']);
            }

            if (isset($step['file'])) {
                $file = $step['file'];

                if (isset($step['line'])) {
                    $line = $step['line'];
                }
            }

            // function()
            $function = $step['function'];

            if (in_array($step['function'], $statements)) {
                if (empty($step['args'])) {
                    // No arguments
                    $args = array();
                } else {
                    // Sanitize the file path
                    $args = array($step['args'][0]);
                }
            } elseif (isset($step['args'])) {
                if (strpos($step['function'], '{closure}') !== false) {
                    // Introspection on closures in a stack trace is impossible
                    $params = null;
                } else {
                    if (isset($step['class'])) {
                        if (method_exists($step['class'], $step['function'])) {
                            $reflection = new ReflectionMethod($step['class'], $step['function']);
                        } else {
                            $reflection = new ReflectionMethod($step['class'], '__call');
                        }
                    } else {
                        $reflection = new ReflectionFunction($step['function']);
                    }

                    // Get the function parameters
                    $params = $reflection->getParameters();
                }

                $args = array();

                foreach ($step['args'] as $i => $arg) {
                    if (isset($params[$i])) {
                        // Assign the argument by the parameter name
                        $args[$params[$i]->name] = $arg;
                    } else {
                        // Assign the argument by number
                        $args[$i] = $arg;
                    }
                }
            }

            if (isset($step['class'])) {
                // Class->method() or Class::method()
                $function = $step['class'] . $step['type'] . $step['function'];
            }

            $output[] = array(
                'function' => $function,
                'args' => isset($args) ? $args : null,
                'file' => isset($file) ? $file : null,
                'line' => isset($line) ? $line : null,
                'source' => isset($source) ? $source : null,
            );

            unset($function, $args, $file, $line, $source);
        }

        return $output;
    }

    /**
     * Native PHP error handler
     *
     * @access    private
     *
     * @param    string    the error severity
     * @param    string    the error string
     * @param    string    the error filepath
     * @param    string    the error line number
     *
     * @return    string
     */
    function show_php_error($severity, $message, $filepath, $line) {
        $severity = (!isset($this->levels[$severity])) ? $severity : $this->levels[$severity];
        throw new Exception("PHP $severity - $message ($filepath:$line)");
    }

    public static function error_php_custom($type, $code, $message, $file, $line, $trace) {
        $error_id = uniqid('error');
        $title = defined("ENV_TITLE") ? ENV_TITLE : "Unknown Project";
        $class = "Exceptions";
        $source = $class::debug_source($file, $line);
        $processed_trace = $class::trace($trace);

        $error = <<<error
<style type="text/css">
#exception_error {
	background: #ddd;
	font-size: 1em;
	font-family:sans-serif;
	text-align: left;
	color: #333333;
}
#exception_error h1,
#exception_error h2 {
	margin: 0;
	padding: 1em;
	font-size: 1em;
	font-weight: normal;
	background: #911911;
	color: #FFFFFF;
}
#exception_error h1 a,
#exception_error h2 a {
	color: #FFFFFF;
}
#exception_error h2 {
	background: #666666;
}
#exception_error h3 {
	margin: 0;
	padding: 0.4em 0 0;
	font-size: 1em;
	font-weight: normal;
}
#exception_error p {
	margin: 0;
	padding: 0.2em 0;
}
#exception_error a {
	color: #1b323b;
}
#exception_error pre {
	overflow: auto;
	white-space: pre-wrap;
}
#exception_error table {
	width: 100%;
	display: block;
	margin: 0 0 0.4em;
	padding: 0;
	border-collapse: collapse;
	background: #fff;
}
#exception_error table td {
	border: solid 1px #ddd;
	text-align: left;
	vertical-align: top;
	padding: 0.4em;
}
#exception_error div.content {
	padding: 0.4em 1em 1em;
	overflow: hidden;
}
#exception_error pre.source {
	margin: 0 0 1em;
	padding: 0.4em;
	background: #fff;
	border: dotted 1px #b7c680;
	line-height: 1.2em;
}
#exception_error pre.source span.line {
	display: block;
}
#exception_error pre.source span.highlight {
	background: #f0eb96;
}
#exception_error pre.source span.line span.number {
	color: #666;
}
#exception_error ol.trace {
	display: block;
	margin: 0 0 0 2em;
	padding: 0;
	list-style: decimal;
}
#exception_error ol.trace li {
	margin: 0;
	padding: 0;
}
.js .collapsed {
	display: none;
}
</style>
<script type="text/javascript">
	document.documentElement.className = 'js';
function koggle(elem)
{
	elem = document.getElementById(elem);

	if (elem.style && elem.style['display'])
		// Only works with the "style" attr
	var disp = elem.style['display'];
	else if (elem.currentStyle)
		// For MSIE, naturally
	var disp = elem.currentStyle['display'];
	else if (window.getComputedStyle)
		// For most other browsers
	var disp = document.defaultView.getComputedStyle(elem, null).getPropertyValue('display');

	// Toggle the state of the "display" style
	elem.style.display = disp == 'block' ? 'none' : 'block';
	return false;
}
</script>

<div id="exception_error">
	<h1><span class="type">[$title] $type [$code]:</span> <span class="message">$message<br />$file:$line</span></h1>
	<div id="$error_id" class="content">

		$source

		<ol class="trace">
error;

        foreach ($processed_trace as $i => $step) {
            $error .= "<li>";
            $error .= "<p>";
            $error .= "<span class='file'>";
            if ($step['file']) {
                $source_id = $error_id . 'source' . $i;
                $error .= "<a href='#$source_id' onclick=\"return koggle('$source_id')\">" . $step['file'] . " [ {$step['line']} ]</a>";
            } else {
                $error .= 'PHP internal call';
            }
            $error .= "</span> &raquo; {$step['function']} (";
            if ($step['args']) {
                $args_id = $error_id . 'args' . $i;
                $error .= "<a href='#$args_id' onclick=\"return koggle('$args_id')\">arguments</a>";
            }
            $error .= ")</p>";

            if ($step['args']) {
                $error .= "<div id='$args_id' class='collapsed'>";
                $error .= "<table cellspacing='0'>";
                foreach ($step['args'] as $name => $arg) {
                    $error .= "<tr>";
                    $error .= "<td><code>$name</code></td>";
                    $error .= "<td><pre>" . TVarDumper::dump($arg) . "</pre></td>";
                    $error .= "</tr>";
                }
                $error .= "</table>";
                $error .= "</div>";
            }

            if (isset($source_id)) {
                $error .= "<pre id='$source_id' class='source collapsed'><code>{$step['source']}</code></pre>";
            }

            $error .= "</li>";
        }

        $error .= "</ol>";
        $error .= "</div>";
        $error .= "<div class='content'>";

        foreach (array('_GET', '_CLEAN_SERVER', '_POST', '_REQUEST') as $var) {
            if (empty($GLOBALS[$var]) || !is_array($GLOBALS[$var])) {
                continue;
            }

            $env_id = $error_id . 'environment' . strtolower($var);

            $error .= '<h3><a href="#' . $env_id . '" onclick="return koggle(\'' . $env_id . '\')">$' . $var . '</a></h3>';
            $error .= '<div id="' . $env_id . '">';
            $error .= '<table cellspacing="0">';
            foreach ($GLOBALS[$var] as $key => $value) {
                $error .= "<tr>";
                $error .= "<td><code>$key</code></td>";
                $error .= "<td><pre>" . TVarDumper::dump($value) . "</pre></td>";
                $error .= "</tr>";
            }

            $error .= "</table>";
            $error .= "</div>";
        }

        $error .= "</div>";

        return $error;
    }

    public static function error_php_enduser($title, $subtitle, $enduser_message) {
        $error = <<<error
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <meta name="viewport" content="width=device-width" />
        <meta name="robots" content="noindex, nofollow" />
        <title>$title</title>
        <style>
            html, body, div, span, applet, object, iframe,
            h1, h2, h3, h4, h5, h6, p, blockquote, pre,
            a, abbr, acronym, address, big, cite, code,
            del, dfn, em, img, ins, kbd, q, s, samp,
            small, strike, strong, sub, sup, tt, var,
            b, u, i, center,
            dl, dt, dd, ol, ul, li,
            fieldset, form, label, legend,
            table, caption, tbody, tfoot, thead, tr, th, td,
            article, aside, canvas, details, embed,
            figure, figcaption, footer, header, hgroup,
            menu, nav, output, ruby, section, summary,
            time, mark, audio, video {
                margin: 0;
                padding: 0;
                border: 0;
                font: inherit;
                font-size: 100%;
                vertical-align: baseline;
            }

            html {
                line-height: 1;
            }

            ol, ul {
                list-style: none;
            }

            table {
                border-collapse: collapse;
                border-spacing: 0;
            }

            caption, th, td {
                text-align: left;
                font-weight: normal;
                vertical-align: middle;
            }

            q, blockquote {
                quotes: none;
            }
            q:before, q:after, blockquote:before, blockquote:after {
                content: "";
                content: none;
            }


            article, aside, details, figcaption, figure, footer, header, hgroup, menu, nav, section, summary {
                display: block;
            }

            body {
                color: #666;
                width: 100%;
                font: 12px Arial, Helvetica, sans-serif;
                background: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAIAAACRXR/mAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyRpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMy1jMDExIDY2LjE0NTY2MSwgMjAxMi8wMi8wNi0xNDo1NjoyNyAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENTNiAoTWFjaW50b3NoKSIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDpBN0QxRTRGQjJEMTcxMUUyQTBCQkVBNDM3RTVDQzgyOSIgeG1wTU06RG9jdW1lbnRJRD0ieG1wLmRpZDpBN0QxRTRGQzJEMTcxMUUyQTBCQkVBNDM3RTVDQzgyOSI+IDx4bXBNTTpEZXJpdmVkRnJvbSBzdFJlZjppbnN0YW5jZUlEPSJ4bXAuaWlkOkE3RDFFNEY5MkQxNzExRTJBMEJCRUE0MzdFNUNDODI5IiBzdFJlZjpkb2N1bWVudElEPSJ4bXAuZGlkOkE3RDFFNEZBMkQxNzExRTJBMEJCRUE0MzdFNUNDODI5Ii8+IDwvcmRmOkRlc2NyaXB0aW9uPiA8L3JkZjpSREY+IDwveDp4bXBtZXRhPiA8P3hwYWNrZXQgZW5kPSJyIj8+lxCQkQAAEJFJREFUWMMFwQmy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") repeat;
            }

            a {
                color: #bbb;
                text-decoration: none;
            }
            a:hover {
                text-decoration: underline;
            }
            a:focus {
                outline: none;
            }

            #wrapper {
                width: 100%;
                max-width: 360px;
                margin: 20px auto;
                background: #fff;
                padding: 30px 30px 10px;
                -webkit-border-radius: 4px;
                -moz-border-radius: 4px;
                -ms-border-radius: 4px;
                -o-border-radius: 4px;
                border-radius: 4px;
                -webkit-box-shadow: 0px 0px 7px #cccccc;
                -moz-box-shadow: 0px 0px 7px #cccccc;
                box-shadow: 0px 0px 7px #cccccc;
            }

            #header-area {
                width: 100%;
                max-width: 420px;
                margin: 80px auto 20px;
                text-align: center;
            }
            #header-area .logo {
                overflow: hidden;
                width: 100%;
                font-size: 36px;
                line-height: 38px;
                text-align: center;
                margin-bottom: 30px;
                color: #142c35;
                padding-bottom: 1px;
                font-family: "Helvetica Neue", Helvetica, Arial, Sans-serif;
                text-shadow: 0 2px #fff;
            }

            @media all and (max-width: 768px) {
                #header-area {
                    margin-top: 30px;
                }
            }
            img {
                border: 0 !important;
            }

            small {
                font-size: 0.85em;
            }

            h1, h2, h3, h4, h4 {
                color: #5D5751;
            }

            h2 {
                text-align: center;
                font-size: 22px;
                margin: 0 0 1em 0;
            }

            p {
                text-align: center;
                font-size: 16px;
                line-height: 1.4em;
                margin-bottom: 1em;
            }

        </style>
    </head>
    <body>
        <div id="header-area">
            <h1 class='logo'>$title</h1>
        </div>
        <div id="wrapper">
            <div id="main" class="form-holder">
                <h2>$subtitle</h2>
                $enduser_message
            </div>
        </div>
    </body>
</html>
error;

        return $error;
    }

}

function exception_send_mail($subject, $original_message) {
    $http = new HTTP_Request();
    return $http->request("http://28hours.org/mailer/index.php/send", 'POST', [
        "subject" => $subject,
        "contents" => $original_message,
    ]);
}

/**
 * Logs a message (and sends it to Bruno) without displaying an error to the user
 * or terminating script execution. Great for notes on the execution of code.
 * Pass any additional arguments you want to this function,
 * they will be included in the email because of the stack trace.
 *
 * @param string $message
 */
function log_without_error($message, $data = array()) {
    Exceptions::exception_handler(new Exception("[Just logging, no error was shown] " . $message), false);
}

/**
 * Throws an exception (which is logged and emailed), displaying an error to the user and terminating script execution.
 * Pass any additional arguments you want to this function,
 * they will be included in the email because of the stack trace.
 *
 * @param string $message
 */
function throw_exception($message, $data = array()) {
    throw new Exception($message);
}

/**
 * Logs an exception (and sends it to Bruno) without displaying an error to the user
 * or terminating script execution. Great for notes on the execution of code.
 *
 * @param Exception $e
 */
function log_exception(Exception $e) {
    Exceptions::exception_handler($e, false);
}

/**
 * TVarDumper class file
 *
 * @author    Qiang Xue <qiang.xue@gmail.com>
 * @link      http://www.pradosoft.com/
 * @copyright Copyright &copy; 2005-2014 PradoSoft
 * @license   http://www.pradosoft.com/license/
 * @package   System.Util
 */

/**
 * TVarDumper class.
 * TVarDumper is intended to replace the buggy PHP function var_dump and print_r.
 * It can correctly identify the recursively referenced objects in a complex
 * object structure. It also has a recursive depth control to avoid indefinite
 * recursive display of some peculiar variables.
 * TVarDumper can be used as follows,
 * <code>
 *   echo TVarDumper::dump($var);
 * </code>
 *
 * @author  Qiang Xue <qiang.xue@gmail.com>
 * @package System.Util
 * @since   3.0
 */
class TVarDumper {
    private static $_objects;
    private static $_output;
    private static $_depth;

    /**
     * Converts a variable into a string representation.
     * This method achieves the similar functionality as var_dump and print_r
     * but is more robust when handling complex objects such as PRADO controls.
     *
     * @param mixed   variable to be dumped
     * @param integer maximum depth that the dumper should go into the variable. Defaults to 10.
     *
     * @return string the string representation of the variable
     */
    public static function dump($var, $depth = 4, $highlight = false) {
        self::$_output = '';
        self::$_objects = array();
        self::$_depth = $depth;
        self::dumpInternal($var, 0);
        if ($highlight) {
            $result = highlight_string("<?php\n" . self::$_output, true);
            return preg_replace('/&lt;\\?php<br \\/>/', '', $result, 1);
        } else
            return self::$_output;
    }

    private static function dumpInternal($var, $level) {
        switch (gettype($var)) {
            case 'boolean':
                self::$_output .= $var ? 'true' : 'false';
                break;
            case 'integer':
                self::$_output .= "$var";
                break;
            case 'double':
                self::$_output .= "$var";
                break;
            case 'string':
                self::$_output .= "'$var'";
                break;
            case 'resource':
                self::$_output .= '{resource}';
                break;
            case 'NULL':
                self::$_output .= "null";
                break;
            case 'unknown type':
                self::$_output .= '{unknown}';
                break;
            case 'array':
                if (self::$_depth <= $level)
                    self::$_output .= 'array(...)';
                else if (empty($var))
                    self::$_output .= 'array()';
                else {
                    $keys = array_keys($var);
                    $spaces = str_repeat(' ', $level * 4);
                    self::$_output .= "array\n" . $spaces . '(';
                    foreach ($keys as $key) {
                        self::$_output .= "\n" . $spaces . "    [$key] => ";
                        self::$_output .= self::dumpInternal($var[$key], $level + 1);
                    }
                    self::$_output .= "\n" . $spaces . ')';
                }
                break;
            case 'object':
                if (($id = array_search($var, self::$_objects, true)) !== false)
                    self::$_output .= get_class($var) . '#' . ($id + 1) . '(...)';
                else if (self::$_depth <= $level)
                    self::$_output .= get_class($var) . '(...)';
                else {
                    $id = array_push(self::$_objects, $var);
                    $className = get_class($var);
                    $members = (array) $var;
                    $keys = array_keys($members);
                    $spaces = str_repeat(' ', $level * 4);
                    self::$_output .= "$className#$id\n" . $spaces . '(';
                    foreach ($keys as $key) {
                        $keyDisplay = strtr(trim($key), array("\0" => ':'));
                        self::$_output .= "\n" . $spaces . "    [$keyDisplay] => ";
                        self::$_output .= self::dumpInternal($members[$key], $level + 1);
                    }
                    self::$_output .= "\n" . $spaces . ')';
                }
                break;
        }
    }
}

# Init.
$exceptions = new Exceptions();