<?php

namespace Glutamatt\Mega ;

use Glutamatt\Mega\Chunks\ChunkToDownloadIterator;
use Spork\ProcessManager;
use Spork\Batch\Strategy\CallbackStrategy;

/*
 * MEGA PHP Client Library.
 */

/**
 * The MEGA API class.
 */
class MEGA {

  /* Global MEGA API server endpoint. */
  const SERVER_GLOBAL = 'g.api.mega.co.nz';

  /* Europe MEGA API server endpoint. */
  const SERVER_EUROPE = 'eu.api.mega.co.nz';

  /* Default server endpoint. */
  private static $server = MEGA::SERVER_GLOBAL;

  /* Use SSL for file transfers. */
  private $use_ssl;

  /* MEGA API path. */
  private $apipath;

  /* Sequence number. */
  private $seqno;

  /* User session ID */
  private $u_sid = NULL;

  /* User master key */
  private $u_k = NULL;

  /* User master key as string */
  private $u_k_aes = NULL;

  /* User RSA private key */
  private $u_privk = NULL;

  public static $debug_mode = false ;
  /**
   * Configure the default MEGA API server endpoint.
   *
   * This method must be invoked before create the client.
   */
  public static function set_default_server($server) {
    self::$server = $server;
  }
  
  public static function human_filesize($bytes, $decimals = 1) {
  	$sz = 'BKMGTP';
  	$factor = floor((strlen($bytes) - 1) / 3);
  	return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
  }

  /**
   * Factory method for getting an instance of MEGA client with authentication
   * using a full user account.
   *
   * @param string $email
   * @param string $password
   * @return MEGA
   */
  public static function create_from_login($email, $password) {
    $client = new MEGA();
    $client->user_login_session($email, $password);
    return $client;
  }

  /**
   * Factory method for getting an instance of MEGA client with authentication
   * using an ephemeral account.
   *
   * @return MEGA
   */
  public static function create_from_ephemeral() {
    // @todo
  }

  /**
   * Factory method for getting an instance of MEGA client restoring a previous
   * saved session.
   *
   * @param string $session
   * @return MEGA
   *
   * @see MEGA::save_session()
   */
  public static function create_from_session($session) {
    $session = unserialize(base64_decode(chunk_split($session)));
    if (!$session || !is_array($session)) {
      return FALSE;
    }
    $client = new MEGA();
    $client->u_k = $session['u_k'];
    $client->u_sid = $session['u_sid'];
    $client->u_privk = $session['u_privk'];
    $client->u_k_aes = MEGAUtil::a32_to_str($session['u_k']);
    return $client;
  }

  /**
   * Default constructor.
   *
   * @param bool $use_ssl
   *   (optional) Use SSL for file transfers (default TRUE).
   * @param string $apipath
   *   (optional) MEGA API path, if ommit use the default configured server.
   */
  public function __construct($use_ssl = TRUE, $apipath = NULL) {
    $this->apipath = isset($apipath) ? $apipath : 'https://' . self::$server . '/';
    $this->use_ssl = (bool) $use_ssl;
    $this->seqno = rand(0, PHP_INT_MAX);
  }

  /**
   * Save current session.
   *
   * @return string
   */
  public function session_save() {
    return chunk_split(base64_encode(serialize(array(
      'u_k' => $client->u_k,
      'u_sid' => $client->u_sid,
      'u_privk' => $client->u_privk,
    ))));
  }

  /**
   * Retrieve folder or user nodes.
   *
   * Returns the contents of the requested folder, or a full view of the
   * requesting user's three filesystem trees, contact list, incoming shares
   * and pending share key requests.
   *
   * @param string $handle
   *   (optional) The public file or user node handle.
   */
  public function node_list($handle = NULL, $args = array()) {
    $req = array('a' => 'f') + $args;
    $req += array('c' => 1);
    if ($handle) {
      $req += array('n' => $handle);
    }

    $res = $this->api_req(array($req));
    if (!$res) {
      return FALSE;
    }

    $res = array_shift($res);
    if (isset($res['f'])) {

      $nodes = &$res['f'];
      foreach ($nodes as $index => $node) {
        if ($node['t'] == 0 || $node['t'] == 1) {
          if (!empty($node['k'])) {
            if ($key = $this->node_decrypt_key($node['k'])) {
              $attr = MEGAUtil::base64_to_str($node['a']);
              $nodes[$index]['a'] = MEGACrypto::dec_attr($attr, $key);
            }
          }
        }
      }
    }
    return $res;
  }

  /**
   * Request file info.
   *
   * @param array $node
   *   The file node handle.
   * @param boolean $dl_url
   *   (optional) Set to TRUE to request a temporary download URL for the file.
   * @param array $args
   *   (optional) Set extra API command arguments.
   *
   * @return array
   *   An array of file information having the following entries:
   *   - s: File size (bytes).
   *   - at: An array of file attributes having the following entries:
   *     - n: File name.
   *   - g: Temporary download URL.
   */
  public function node_file_info($node, $dl_url = FALSE, $args = array()) {
    if (empty($node['h']) || empty($node['k'])) {
      throw new \InvalidArgumentException('Invalid file node handle');
    }

    $req = array('a' => 'g') + $args;
    $req += array('n' => $node['h'], 'g' => (int) $dl_url, 'ssl' => (int) $this->use_ssl);

    $res = $this->api_req(array($req));
    if (!$res || !is_array($res)) {
      return FALSE;
    }

    $res = array_shift($res);
    if (isset($res['at'])) {
      if ($key = $this->node_decrypt_key($node['k'])) {
        $attr = MEGAUtil::base64_to_str($res['at']);
        $res['at'] = MEGACrypto::dec_attr($attr, $key);
      }
    }
    return $res;
  }

  /**
   * Download file.
   *
   * @param array $node
   * @param resource $dest
   *   (optional) The destination stream.
   *
   * @return
   */
  public function node_file_download($node, $dest = NULL) {
    // Requests a temporary download URL for a file node.
    $info = $this->node_file_info($node, TRUE);
    if (!$info || empty($info['g'])) {
      return FALSE;
    }

    $key = $this->node_decrypt_key($node['k']);
    if (!$key) {
      return FALSE;
    }

    if (is_null($dest)) {
      $handle = fopen('php://memory', 'wb');
    }
    else {
      $handle = $dest;
    }

    $ret = $this->file_download_url($info['g'], $info['s'], $key, $handle);

    if (is_null($dest)) {
      $content = stream_get_contents($handle);
      fclose($handle);
      return $content;
    }

    return $ret;
  }

  /**
   * Download and save file to disk.
   *
   * @param array $node
   *   The file node handle.
   * @param string $dir_path
   *   (optional) Target directory.
   * @param string $filename
   *   (optional) File name.
   * @param array $args
   *
   * @return string
   *   The full path of saved file.
   */
  public function node_file_save($node, $dir_path = NULL, $filename = NULL, $args = array()) {
    // Requests a temporary download URL for a file node.
    $info = $this->node_file_info($node, TRUE, $args);
    if (!$info || empty($info['g'])) {
      return FALSE;
    }

    $key = $this->node_decrypt_key($node['k']);
    if (!$key) {
      return FALSE;
    }

    $path = !empty($dir_path) ? rtrim($dir_path, '/\\') . '/' : '';
    $path .= !empty($filename) ? $filename : $info['at']['n'];

    $stream = fopen($path, 'wb');
    try {
      $this->log("Downloading {$info['at']['n']} (size: {$info['s']}), url = {$info['g']}");
      $this->file_download_url($info['g'], $info['s'], $key, $stream);
    }
    catch (MEGAException $e) {
      fclose($stream);
      throw $e;
    }
    fclose($stream);

    return $path;
  }

  /**
   * Request file info.
   *
   * This operation not require authentication.
   *
   * @param string $ph
   *   The public file node handle.
   * @param string $key
   *   The file node key.
   * @param boolean $dl_url
   *   (optional) Requests a temporary download URL for the file node.
   * @param array $args
   *   (optional) Extra API command arguments.
   *
   * @return array
   *   An array of file information having the following entries:
   *   - s: File size (bytes).
   *   - at: An array of file attributes having the following entries:
   *     - n: File name.
   *   - g: Temporary download URL.
   */
  public function public_file_info($ph, $key, $dl_url = FALSE, $args = array()) {
    $req = array('a' => 'g') + $args;
    $req += array('p' => $ph, 'g' => (int) $dl_url, 'ssl' => (int) $this->use_ssl);

    $res = $this->api_req(array($req));
    if (!$res || !is_array($res)) {
      return FALSE;
    }

    $res = array_shift($res);
    if (isset($res['at'])) {
      $key = MEGAUtil::base64_to_a32($key);
      $attr = MEGAUtil::base64_to_str($res['at']);
      $res['at'] = MEGACrypto::dec_attr($attr, $key);
    }
    return $res;
  }

  /**
   * Request file info from link.
   *
   * @see public_file_info()
   */
  public function public_file_info_from_link($link, $dl_url = FALSE) {
  	
  	if($this->is_mega_crypter_link($link))
  		return $this->megacrypter_file_info($link,1) ;
  		
    $file = self::parse_link($link);
    if (empty($file['ph'])) {
      throw new \InvalidArgumentException('Public handle not found');
    }
    if (empty($file['key'])) {
      throw new \InvalidArgumentException('Private key not found');
    }
    return $this->public_file_info($file['ph'], $file['key'], $dl_url);
  }

  /**
   *
   * @param unknown $ph
   * @param unknown $key
   * @param string $dir_path
   * @param string $filename
   * @param unknown $args
   */
  public function public_file_download($ph, $key, $dest = NULL) {
  }

  public function public_file_download_from_link($link, $dest = NULL) {
  }

  /**
   *
   * This operation not require authentication.
   *
   * @param string $ph
   * @param string $key
   * @param string $dir_path
   * @param string $filename
   * @param array $args
   * @return boolean|string
   */
  public function public_file_save($ph, $key, $dir_path = NULL, $filename = NULL , $stream_path = NULL) {
    // Requests a temporary download URL for a file node.
    $info = $this->public_file_info($ph, $key, TRUE);
    if (!$info || empty($info['g'])) {
      return FALSE;
    }

    $path = !empty($dir_path) ? rtrim($dir_path, '/\\') . '/' : '';
    $path .= !empty($filename) ? $filename : $info['at']['n'];
    $path = $stream_path?$stream_path:$path ;

    $try_resume = is_file($path) ;
    $stream = fopen($path, $try_resume?'ab':'wb');
    try {
      $this->log("Downloading {$info['at']['n']} (size: {$info['s']}), url = {$info['g']}");
      $this->file_download_url($info['g'], $info['s'], MEGAUtil::base64_to_a32($key), $stream, $try_resume);
    }
    catch (MEGAException $e) {
      fclose($stream);
      throw $e;
    }
    fclose($stream);

    return $path;
  }

  /**
   *
   * This operation not require authentication.
   *
   * @see public_file_save()
   */
  public function public_file_save_from_link($link, $dir_path = NULL, $filename = NULL , $stream_path = NULL) {
  	
  	if( $this->is_mega_crypter_link($link)) return 
  		$this->public_file_save_from_mega_crypter_link($link, $dir_path , $filename  , $stream_path) ;
  	
    $file = self::parse_link($link);
    if (!isset($file['ph']) || !isset($file['key'])) {
      throw new \Exception('Invalid link');
    }
    return $this->public_file_save($file['ph'], $file['key'], $dir_path, $filename , $stream_path);
  }
  
  public function is_mega_crypter_link( $link )
  {
  	return preg_match( '#megacrypter#' , $link ) ;
  }

  /**
   * Add/copy nodes.
   *
   * Adds new nodes. Copies existing files and adds completed uploads to a
   * user's filesystem.
   */
  public function node_add() {
    throw \Exception('Not implemented');
  }

  /**
   * Delete node.
   *
   * Deletes a node, including all of its subnodes.
   */
  public function node_delete() {
    throw \Exception('Not implemented');
  }

  /**
   * Move node.
   *
   * Moves a node to a new parent node.
   */
  public function node_move() {
    throw \Exception('Not implemented');
  }

  /**
   * Set node attributes.
   *
   * Updates the encrypted node attributes object.
   */
  public function node_update() {
    throw \Exception('Not implemented');
  }

  /**
   * Create/delete public handle.
   *
   * Enables or disables the public handle for a node.
   */
  public function node_publish($op) {
    throw \Exception('Not implemented');
  }

  public function node_unpublish($op) {
    throw \Exception('Not implemented');
  }

  /**
   * Create/modify/delete outgoing share.
   *
   * Controls the sharing status of a node.
   */
  public function node_share($op) {
    throw \Exception('Not implemented');
  }

  /**
   * Login session challenge/response.
   *
   * Establishes a user session based on the response to a cryptographic challenge.
   *
   * @see user.js::u_login()
   * @see user.js::api_getsid()
   */
  public function user_login_session($email, $password, $args = array()) {
    $this->log("Preparing user key...");
    $pk = MEGACrypto::prepare_key_pw($password);

    $this->log("Preparing user hash...");
    $uh = MEGACrypto::stringhash(strtolower($email), $pk);

    $req = array('a' => 'us') + $args;
    $req += array('user' => $email, 'uh' => $uh);

    $res = $this->api_req(array($req));
    if (!$res || !is_array($res)) {
      return FALSE;
    }

    $res = array_shift($res);
    if (isset($res['k'])) {
      // decrypt master key
      $k = MEGAUtil::base64_to_a32($res['k']);
      if (count($k) == 4) {
        $k = MEGACrypto::decrypt_key($pk, $k);
        if (isset($res['tsid'])) {
          // @todo
        }
        else if (isset($res['csid'])) {
          $privk = MEGACrypto::decrypt_key(MEGAUtil::a32_to_str($k), MEGAUtil::base64_to_a32($res['privk']));
          $privk = MEGAUtil::a32_to_str($privk);

          $rsa_privk = array();
          // decompose private key
          for ($i = 0; $i < 4; $i++) {
            $l = ((ord($privk[0]) * 256 + ord($privk[1]) + 7) >> 3) + 2;
            $rsa_privk[$i] = MEGAUtil::mpi2b(substr($privk, 0, $l));
            $privk = substr($privk, $l);
          }

          $t = MEGAUtil::base64urldecode($res['csid']);
          $t = MEGAUtil::mpi2b($t);

          $sid = MEGARsa::rsa_decrypt($t, $rsa_privk[0], $rsa_privk[1], $rsa_privk[2]);
          $sid = MEGAUtil::base64urlencode(substr(strrev($sid), 0, 43));

          // check format
          if ($i == 4 && strlen($privk) < 16) {
            // @@@ check remaining padding for added early wrong password detection likelihood
            $r = array(
              $k,
              $sid,
              $rsa_privk,
            );
            $this->u_k = $k;
            $this->u_k_aes = MEGAUtil::a32_to_str($this->u_k);
            $this->u_sid = $sid;
            $this->u_privk = $rsa_privk;
            return $r;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   *
   */
  public function user_add($args = array()) {
    $master_key = array();
    $password_key = array();
    $session_self_challenge = array();

    foreach (range(0, 3) as $n) {
      $master_key[] = rand(0, PHP_INT_MAX);
      $password_key[] = rand(0, PHP_INT_MAX);
      $session_self_challenge[] = rand(0, PHP_INT_MAX);
    }

    $k = MEGACrypto::encrypt_key(MEGAUtil::a32_to_str($password_key), $master_key);
    $ts = MEGACrypto::encrypt_key(MEGAUtil::a32_to_str($master_key), $session_self_challenge);
    $ts = MEGAUtil::a32_to_str($session_self_challenge) . MEGAUtil::a32_to_str($ts);

    $req = array('a' => 'up') + $args;
    $req += array('k' => MEGAUtil::a32_to_base64($k), 'ts' => MEGAUtil::base64urlencode($ts));

    $res = $this->api_req(array($req));
    if (!$res || !is_array($res)) {
      return FALSE;
    }
    return array_shift($res);
  }

  /**
   * Get user.
   *
   * Retrieves user details.
   */
  public function user_get_details() {
  }

  /**
   * Download a file node from requested temporary download URL.
   *
   * @param array $info
   *   The file info returned by node_file_info() or public_file_info(),
   *   with requested temporary download URL.
   * @param resource $stream
   *   Stream resource.
   * @param string $key
   *   The file node key.
   *
   * @return array
   *   An array of file information having the following entries:
   *   - s: File size (bytes).
   *   - at: An array of file attributes having the following entries:
   *     - n: File name.
   *
   * @todo Add range support
   * @todo Add integrity check
   */
	protected function file_download_url($url, $size, $key, $dest, $try_resume = false ) {
    // Open the cipher
    $td = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', 'ctr', '');

    // Create key
    //$key = MEGAUtil::base64_to_a32($key);
    $aeskey = array($key[0] ^ $key[4], $key[1] ^ $key[5], $key[2] ^ $key[6], $key[3] ^ $key[7]);
    $aeskey = MEGAUtil::a32_to_str($aeskey);

    // Create the IV
    $iv = array($key[4], $key[5], 0, 0);
    $iv = MEGAUtil::a32_to_str($iv);

    // Initialize encryption module for decryption
    mcrypt_generic_init($td, $aeskey, $iv);

    $tmp_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'megagluta' . DIRECTORY_SEPARATOR . md5($aeskey) . DIRECTORY_SEPARATOR ;
    $next_to_add_file = $tmp_path . 'next_to_add' ;
    $resume_dl = $try_resume && is_dir($tmp_path) && is_file( $next_to_add_file );
    if(!$resume_dl) @mkdir($tmp_path, 0777, true) ;
    $chunks = $this->get_chunks_list($size);
    $ret = 0;
    
    $id_chunks = 0 ;
    foreach ($chunks as $chunk_start => $chunk_size)
    	$process_chunks[] = ['start' => $chunk_start, 'size' => $chunk_size, 'id' => $id_chunks++] ;
    
    ChunkToDownloadIterator::prepare($process_chunks, $tmp_path . 'to_dl' . DIRECTORY_SEPARATOR, $resume_dl) ;
    
    $manager = new ProcessManager() ;
    $manager->process(null, function($chunk_info) use ($tmp_path, $url) {
    	$this->chunk_download($url, $tmp_path, $chunk_info['id'], $chunk_info['start'], $chunk_info['size']) ;
    }, new CallbackStrategy(function() {
		$batches = [] ;
		for ($i = 0 ; $i < 10 ; $i++) $batches[] = new ChunkToDownloadIterator($i) ;
		return $batches ;
	})) ;
    
    $next_chunk_id = 0 ;
    if( $resume_dl ) $next_chunk_id = intval( file_get_contents( $next_to_add_file ) ) ;
    $pending_time = 0 ;
    $wait_delay = 1000 * 200 ;
    $time_out = 1000 * 1000 * 10 ;
    while ( $next_chunk_id < count($chunks) && ($next_file = $tmp_path . $next_chunk_id . '.chunk') )
    {
    	usleep($wait_delay);
    	$pending_time += $wait_delay ;
    	if($pending_time > $time_out) {
    		if(!ChunkToDownloadIterator::add_value_file($next_chunk_id, $process_chunks[$next_chunk_id]))
    			$this->chunk_download($url, $tmp_path, $next_chunk_id, $process_chunks[$next_chunk_id]['start'], $process_chunks[$next_chunk_id]['size']) ;
    	}
	    if(!is_readable($next_file) || !rename($next_file , ( $next_file = $next_file . '.reading')) )
	    	continue ;
	    $ret += fwrite($dest, mdecrypt_generic($td, file_get_contents($next_file))) ;
	    unlink($next_file) ;
	    file_put_contents( $next_to_add_file , ++$next_chunk_id ) ;
    	$pending_time = 0 ;
    }
    
    $manager->killAll() ;
    mcrypt_generic_deinit($td);
    mcrypt_module_close($td);
    
    return $ret;
  }
  
  protected function megacrypter_request( $method , $link )
  {
  	$context = stream_context_create(array ('http' => array (
  			'method' => 'POST',
  			'header'=> 'Content-type: application/json',
  			'content' => json_encode(['m' => $method, 'link'  => $link])))) ;
  	return json_decode(file_get_contents('http://megacrypter.com/api', null, $context), 1) ;
  }
  
  protected function megacrypter_file_info( $link , $emule_mega = false )
  {
  	$info = $this->megacrypter_request('info', $link ) ;
  	if( !$key = @$info['key'] ) {
  		throw new \ErrorException('key not found :(');
  		return false ;
  	}
  	$dl = $this->megacrypter_request('dl', $link ) ;
  	if( !$storage_url = @$dl['url'] ) {
  		throw new \ErrorException('url not found :(');
  		return false ;
  	}
  	if( !$size = @$info['size'] ) {
	  	stream_context_set_default(['http'=>['method'=>'HEAD']]) ;
	  	$headers = get_headers($storage_url, 1) ;
	  	$size = $headers['Content-Length'] ;
  	}
  	$return =  [ 'size' => $size , 'storage_url' => $storage_url , 'key' => $key , 'name' => $info['name'] ] ;
  	if($emule_mega) return ['s' => $return['size'], 'at' => ['n' => $return['name']]] ;
  	return $return ;
  }
  
  public function public_file_save_from_mega_crypter_link( $link , $dir_path = NULL, $filename = NULL , $stream_path = NULL)
  {
  	$info = $this->megacrypter_file_info( $link ) ;
  	
  	if(! $info ) return false ;
  	
  	$path = !empty($dir_path) ? rtrim($dir_path, '/\\') . '/' : '';
  	$path .= !empty($filename) ? $filename : $info['name'];
  	$path = $stream_path?$stream_path:$path ;
  	
  	$try_resume = is_file($path) ;
    $stream = fopen($path, $try_resume?'ab':'wb');
  	try {
  		$this->log("Downloading {$info['name']} (size: {$info['size']}), url = {$info['storage_url']}");
  		$this->file_download_url($info['storage_url'], $info['size'], MEGAUtil::base64_to_a32($info['key']), $stream, $try_resume);
  	}
  	catch (MEGAException $e) {
  		fclose($stream);
  		throw $e;
  	}
  	fclose($stream);
  	
  	return $path;
  }
  
  protected function chunk_download($url, $tmp_path, $chunk_id, $chunk_start, $chunk_size)
  {
  	$chunk_tmp_file_name = $tmp_path . uniqid() ;
  	$chunk_stream = $this->get_chunk_stream($url, $chunk_start, $chunk_size) ;
  	if(!$chunk_stream) return ;
  	file_put_contents($chunk_tmp_file_name, $chunk_stream ) ;
  	rename($chunk_tmp_file_name , $tmp_path . $chunk_id . '.chunk') ;
  }
  
  protected function get_chunk_stream($url, $chunk_start, $chunk_size)
  {
  	$url_parts = parse_url($url) ;
  	$path = $url_parts['path'] . '/' . $chunk_start . '-' . ($chunk_start + $chunk_size - 1) ;
  	$http = [
  	"Accept: */*" , "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko)" .
  	"Ubuntu Chromium/28.0.1500.71 Chrome/28.0.1500.71 Safari/537.36" , "MEGA-Chrome-Antileak: $path" , "Origin: https://mega.co.nz" ,
  	"Referer: https://mega.co.nz/" , "Content-Length: 0" , "Accept-Encoding: gzip,deflate,sdch" , "Connection: close",
  	"Accept-Language: fr-FR,fr;q=0.8,en-US;q=0.6,en;q=0.4" ] ;
  	$timeout = 5 ;
  	$context = stream_context_create(array ('http' => array (
  			'method' => 'POST',
  			'header'=> implode("\r\n", $http),
  			'timeout' => $timeout ,
  			'content' => ''))) ;
  	$fp = fopen('https://' . $url_parts['host'] , 'rb', false, $context);
  	if(!$fp) throw new MEGAException(MEGAException::EHTTPSCONNECT) ;
  	$stream = '' ;
  	stream_set_timeout($fp, $timeout);
  	$meta = stream_get_meta_data($fp);
  	while(($streamlen  = strlen($stream)) < $chunk_size)
  	{
  		if($meta['timed_out']) return ;
  		$stream .= fread($fp, min(1024, $chunk_size - $streamlen));
  		$meta = stream_get_meta_data($fp);
  	}
  	fclose($fp) ;
  	return $stream ;
  }
  
  protected function get_chunks_list($size) {
  	$chunks = array();
  	$p = $pp = 0;
  	$i = 1;
  
  	while ($i <= 8 && $p < ($size - $i * 0x20000)) {
  		$chunks[$p] = $i * 0x20000;
  		$pp = $p;
  		$p += $chunks[$p];
  		$i += 1;
  	}
  
  	while ($p < $size) {
  		$chunks[$p] = 0x100000;
  		$pp = $p;
  		$p += $chunks[$p];
  	}
  
  	$chunks[$pp] = $size - $pp;
  	if (empty($chunks[$pp])) {
  		unset($chunks[$pp]);
  	}
  
  	return $chunks;
  }

  protected function node_decrypt_key($k) {
    static $cache = array();
    if (!isset($cache[$k])) {
      $keys = explode('/', $k);
      list (, $key) = explode(':', $keys[0]);
      if (!empty($key)) {
        $key = MEGAUtil::base64_to_a32($key);
        $key = MEGACrypto::decrypt_key($this->u_k_aes, $key);
        $cache[$k] = $key;
      }
    }
    return $cache[$k];
  }

  protected function api_req($req, $params = array()) {
    $this->api_req_alter($req);

    $payload = is_string($req) ? $req : json_encode($req);

    $url = $this->apipath . 'cs?id=' . $this->seqno;
    if (!empty($this->u_sid)) {
      $url .= '&sid=' . $this->u_sid;
    }

    $this->log("Making API request: " . $payload);
    $this->seqno ++;

    $response = $this->http_do_request($url, $payload);
    /*
     if ($response->error) {
    $this->log("API request error (" . $response->code . ")");
    return FALSE;
    }
    */

    $this->log('API response: ' . $response);

    return json_decode($response, TRUE);
  }

  protected function api_req_alter(&$req) { }

  /**
   *
   *
   */
  protected function http_do_request($url, $payload) {
    //$url = ($this->ssl ? 'https' : 'http') . '://' . $this->endpoint . '/cs?id=' . $this->sequence_number;

    $curl_handle = curl_init();
    $curl_options = array(
      CURLOPT_URL => $url,
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_SSL_VERIFYPEER => FALSE, // Required to run on https.
      CURLOPT_SSL_VERIFYHOST => FALSE, // Required to run on https.
      //CURLOPT_HEADERFUNCTION => array(&$this, 'curlHeaderCallback'),
      //CURLOPT_USERAGENT => $this->databasePrefix,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => array('Content-Type' => 'application/json'),
    );

    //print_r($curl_options);

    curl_setopt_array($curl_handle, $curl_options);

    $content = curl_exec($curl_handle);

    //$status = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
    //print "Status: $status\n";

    curl_close($curl_handle);

    return $content;
  }

  protected function http_open_stream($url, $options = array()) {
    $scheme = parse_url($url, PHP_URL_SCHEME);

    /*
     $header = '';
    foreach ($options['headers'] as $name => $value) {
    $header .= $name . ': ' . trim($value) . "\r\n";
    }
    */

    $opts = array(
      $scheme => array(
        'method' => 'GET',
        //'header' => $header,
        //'content' => $options['data'],
        //'max_redirects' => $options['max_redirects'],
        //'timeout' => (float) $options['timeout'],
      )
    );

    $context = stream_context_create($opts);
    return fopen($url, 'rb', FALSE, $context);
  }

  protected function log($message) {
  	if(self::$debug_mode)
    	echo "[DEBUG] MEGA::$message\n";
  }

  /**
   *
   * @param string $link
   * @return array
   */
  public static function parse_link($link, $component = NULL) {
    $fragment = parse_url($link, PHP_URL_FRAGMENT);
    if (empty($fragment)) {
      return FALSE;
    }
    $matches = array();
    if (preg_match('/^(F?)\\!([a-zA-Z0-9]+)(?:\\!([a-zA-Z0-9_,\\-]+))?/', $fragment, $matches)) {
      //return count($matches) > 2 ? array($matches[1], $matches[3]) : array($matches[1]);
      return array(
        'type' => $matches[1] == 'F' ? 'folder' : 'file',
        'ph'   => $matches[2],
      ) + (!empty($matches[3]) ? array('key' => $matches[3]) : array());
    }
    return FALSE;
  }
}

class MEGAException extends \Exception {

  const EINTERNAL = -1;
  const EARGS = -2;
  const EAGAIN = -3;
  const ERATELIMIT = -4;
  const EFAILED = -5;
  const ETOOMANY = -6;
  const ERANGE = -7;
  const EEXPIRED = -8;
  const ENOENT = -9;
  const ECIRCULAR = -10;
  const EACCESS = -11;
  const EEXIST = -12;
  const EINCOMPLETE = -13;
  const EKEY = -14;
  const ESID = -15;
  const EBLOCKED = -16;
  const EOVERQUOTA = -17;
  const ETEMPUNAVAIL = -18;
  const EHTTPSCONNECT = -19;

  public function __construct($code) {
    $message = NULL;
    switch ($code) {
      case self::EINTERNAL:
        $message = 'An internal error has occurred. Please submit a bug report, detailing the exact circumstances in which this error occurred';
        break;
      case self::EARGS:
        $message = 'You have passed invalid arguments to this command';
        break;
      case self::EAGAIN:
        $message = 'A temporary congestion or server malfunction prevented your request from being processed. No data was altered. Retry. Retries must be spaced with exponential backoff';
        break;
      case self::ERATELIMIT:
        $message = 'You have exceeded your command weight per time quota. Please wait a few seconds, then try again (this should never happen in sane real-life applications)';
        break;
      case self::EFAILED:
        $message = 'The upload failed. Please restart it from scratch';
        break;
      case self::ETOOMANY:
        $message = 'Too many concurrent IP addresses are accessing this upload target URL';
        break;
      case self::ERANGE:
        $message = 'The upload file packet is out of range or not starting and ending on a chunk boundary';
        break;
      case self::EEXPIRED:
        $message = 'The upload target URL you are trying to access has expired. Please request a fresh one';
        break;
      case self::ENOENT:
        $message = 'Object (typically, node or user) not found';
        break;
      case self::ECIRCULAR:
        $message = 'Circular linkage attempted';
        break;
      case self::EACCESS:
        $message = 'Access violation (e.g., trying to write to a read-only share)';
        break;
      case self::EEXIST:
        $message = 'Trying to create an object that already exists';
        break;
      case self::EINCOMPLETE:
        $message = 'Trying to access an incomplete resource';
        break;
      case self::EKEY:
        $message = 'A decryption operation failed (never returned by the API)';
        break;
      case self::ESID:
        $message = 'Invalid or expired user session, please relogin';
        break;
      case self::EBLOCKED:
        $message = 'User blocked';
        break;
      case self::EOVERQUOTA:
        $message = 'Request over quota';
        break;
      case self::ETEMPUNAVAIL:
        $message = 'Resource temporarily not available, please try again later';
        break;
      case self::EHTTPSCONNECT:
      	$message = 'Unable to connect https';
      	break;
    }
    parent::__construct($message, $code);
  }
}

// === crypto_2.js ==

/**
 * PHP port of MEGA Javascript crypto functions.
 *
 * @see http://eu.static.mega.co.nz/crypto_N.js
 */
class MEGACrypto {

  /**
   * Convert user-supplied password array.
   *
   * @param array $a
   *   The user password array of 32-bit words.
   *
   * @return string
   *   The AES user password key.
   */
  public static function prepare_key($a) {
    $pkey = MEGAUtil::a32_to_str(array(0x93C467E3, 0x7DB0C7A4, 0xD1BE3F81, 0x0152CB56));
    $total = count($a);
    for ($r = 65536; $r--; ) {
      for ($j = 0; $j < $total; $j += 4) {
        $key = array(0, 0, 0, 0);
        for ($i = 0; $i < 4; $i++) {
          if ($i + $j < $total) {
            $key[$i] = $a[$i + $j];
          }
        }
        $pkey = self::encrypt_aes_cbc(MEGAUtil::a32_to_str($key), $pkey);
      }
    }
    return $pkey;
  }

  // prepare_key with string input
  public static function prepare_key_pw($password) {
    return self::prepare_key(MEGAUtil::str_to_a32($password));
  }

  public static function stringhash($s, $aeskey) {
    $s32 = MEGAUtil::str_to_a32($s);
    $h32 = array(0, 0, 0, 0);

    for ($i = 0; $i < count($s32); $i++) {
      $h32[$i & 3] ^= $s32[$i];
    }

    $h32 = MEGAUtil::a32_to_str($h32);
    for ($i = 16384; $i--;) {
      $h32 = self::encrypt_aes_cbc($aeskey, $h32);
    }

    $h32 = MEGAUtil::str_to_a32($h32);
    return MEGAUtil::a32_to_base64(array($h32[0], $h32[2]));
  }

  // AES encrypt in CBC mode (zero IV)
  public static function encrypt_aes_cbc($key, $data) {
    $iv = str_repeat("\0", mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC));
    return mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $data, MCRYPT_MODE_CBC, $iv);
  }

  // AES decrypt in CBC mode (zero IV)
  public static function decrypt_aes_cbc($key, $data) {
    $iv = str_repeat("\0", mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC));
    return mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, $data, MCRYPT_MODE_CBC, $iv);
  }

  // AES encrypt in CBC mode (zero IV)
  public static function encrypt_aes_cbc_a32($key, $a) {
    return MEGAUtil::str_to_a32(self::encrypt_aes_cbc($key, MEGAUtil::a32_to_str($a)));
  }

  // AES decrypt in CBC mode (zero IV)
  public static function decrypt_aes_cbc_a32($key, $a) {
    return MEGAUtil::str_to_a32(self::decrypt_aes_cbc($key, MEGAUtil::a32_to_str($a)));
  }

  // encrypt 4- or 8-element 32-bit integer array
  public static function encrypt_key($key, $a) {
    if (count($a) == 4) {
      return self::encrypt_aes_cbc_a32($key, $a);
    }
    $x = array();
    for ($i = 0; $i < count($a); $i += 4) {
      $x[] = self::encrypt_aes_cbc_a32($key, array($a[$i], $a[$i + 1], $a[$i + 2], $a[$i + 3]));
    }
    return $x;
  }

  /**
   * decrypt 4- or 8-element 32-bit integer array
   *
   * @param string $key
   * @param array $a
   * @return array
   */
  public static function decrypt_key($key, $a) {
    if (count($a) == 4) {
      return self::decrypt_aes_cbc_a32($key, $a);
    }
    $x = array();
    for ($i = 0; $i < count($a); $i += 4) {
      //$x[] = self::decrypt_aes_cbc_a32($key, array($a[$i], $a[$i + 1], $a[$i + 2], $a[$i + 3]));
      $y = self::decrypt_aes_cbc_a32($key, array($a[$i], $a[$i + 1], $a[$i + 2], $a[$i + 3]));
      $x = array_merge($x, $y);
    }
    return $x;
  }

  // generate attributes block using AES-CBC with MEGA canary
  // attr = Object, key = [] (four-word random key will be generated) or Array(8) (lower four words will be used)
  // returns [ArrayBuffer data,Array key]
  public static function enc_attr($attr, $key) {
  }

  // decrypt attributes block using AES-CBC, check for MEGA canary
  // attr = ab, key as with enc_attr
  // returns [Object] or false
  public static function dec_attr($attr, $key) {
    if (count($key) != 4) {
      $key = array($key[0] ^ $key[4], $key[1] ^ $key[5], $key[2] ^ $key[6], $key[3] ^ $key[7]);
    }
    $key = MEGAUtil::a32_to_str($key);

    $attr = self::decrypt_aes_cbc($key, $attr);
    $attr = MEGAUtil::str_depad($attr);

    if (substr($attr, 0, 6) != 'MEGA{"') {
      return FALSE;
    }

    // @todo protect against syntax errors
    $attr = json_decode(MEGAUtil::from8(substr($attr, 4)), TRUE);
    if (is_null($attr)) {
      $attr = new \stdClass();
      $attr['n'] = 'MALFORMED_ATTRIBUTES';
    }
    return $attr;
  }
}

/**
 * RSA-related stuff -- taken from PEAR Crypt_RSA package
 * http://pear.php.net/package/Crypt_RSA
 */
class MEGARsa {

  public static function rsa_decrypt($enc_data, $p, $q, $d) {
    $enc_data = self::int2bin($enc_data);
    $exp = $d;
    $modulus = bcmul($p, $q);
    $data_len = strlen($enc_data);
    $chunk_len = self::bitLen($modulus) - 1;
    $block_len = (int) ceil($chunk_len / 8);
    $curr_pos = 0;
    $bit_pos = 0;
    $plain_data = 0;

    while ($curr_pos < $data_len) {
      $tmp = self::bin2int(substr($enc_data, $curr_pos, $block_len));
      $tmp = bcpowmod($tmp, $exp, $modulus);
      $plain_data = self::bitOr($plain_data, $tmp, $bit_pos);
      $bit_pos += $chunk_len;
      $curr_pos += $block_len;
    }

    return self::int2bin($plain_data);
  }

  private static function bin2int($str) {
    $result = 0;
    $n = strlen($str);
    do {
      $result = bcadd(bcmul($result, 256), ord($str[--$n]));
    } while ($n > 0);
    return $result;
  }

  private static function int2bin($num) {
    $result = '';
    do {
      $result .= chr(bcmod($num, 256));
      $num = bcdiv($num, 256);
    } while (bccomp($num, 0));
    return $result;
  }

  private static function bitOr($num1, $num2, $start_pos) {
    $start_byte = intval($start_pos / 8);
    $start_bit = $start_pos % 8;
    $tmp1 = self::int2bin($num1);

    $num2 = bcmul($num2, 1 << $start_bit);
    $tmp2 = self::int2bin($num2);
    if ($start_byte < strlen($tmp1)) {
      $tmp2 |= substr($tmp1, $start_byte);
      $tmp1 = substr($tmp1, 0, $start_byte) . $tmp2;
    } else {
      $tmp1 = str_pad($tmp1, $start_byte, '\0') . $tmp2;
    }
    return self::bin2int($tmp1);
  }

  private static function bitLen($num) {
    $tmp = self::int2bin($num);
    $bit_len = strlen($tmp) * 8;
    $tmp = ord($tmp[strlen($tmp) - 1]);
    if (!$tmp) {
      $bit_len -= 8;
    } else {
      while (!($tmp & 0x80)) {
        $bit_len--;
        $tmp <<= 1;
      }
    }
    return $bit_len;
  }
}

/**
 * PHP port of MEGA Javascript util functions.
 */
class MEGAUtil {

  // unsubstitute standard base64 special characters, restore padding.
  public static function base64urldecode($data) {
    $data .= substr('==', (2 - strlen($data) * 3) & 3);
    $data = str_replace(array('-', '_', ','), array('+', '/', ''), $data);
    return base64_decode($data);
  }

  // substitute standard base64 special characters to prevent JSON escaping, remove padding
  public static function base64urlencode($data) {
    $data = base64_encode($data);
    return str_replace(array('+', '/', '='), array('-', '_', ''), $data);
  }

  // array of 32-bit words to string (big endian)
  public static function a32_to_str($a) {
    return call_user_func_array('pack', array_merge(array('N*'), $a));
  }

  public static function a32_to_base64($a) {
    return self::base64urlencode(self::a32_to_str($a));
  }

  // string to array of 32-bit words (big endian)
  public static function str_to_a32($b) {
    $padding = (((strlen($b) + 3) >> 2) * 4) - strlen($b);
    if ($padding > 0) {
      $b .= str_repeat("\0", $padding);
    }
    return array_values(unpack('N*', $b));
  }

  public static function base64_to_a32($s) {
    return self::str_to_a32(self::base64urldecode($s));
  }

  // string to binary string (ab_to_base64)
  public static function str_to_base64($ab) {
    return self::base64urlencode($ab);
  }

  // binary string to string, 0-padded to AES block size (base64_to_ab)
  public static function base64_to_str($a) {
    return self::str_pad(self::base64urldecode($a));
  }

  // binary string depadding (ab_to_str_depad)
  public static function str_depad($b) {
    for ($i = strlen($b); $i-- && !uniord($b[$i]); );
    $b = substr($b, 0, $i + 1);
    return $b;
  }

  // binary string 0-padded to AES block size (str_to_ab)
  public static function str_pad($b) {
    $padding = 16 - ((strlen($b) - 1) & 15);
    return $b . str_repeat("\0", $padding - 1);
  }

  public static function mpi2b($s) {
    $s = bin2hex(substr($s, 2));
    $len = strlen($s);
    $n = 0;
    for ($i = 0; $i < $len; $i++) {
      $n = bcadd($n, bcmul(hexdec($s[$i]), bcpow(16, $len - $i - 1)));
    }
    return $n;
  }

  public static function to8($unicode) {
    return $unicode;
    //return unescape(self::encodeURIComponent($unicode));
  }

  public static function from8($utf8) {
    return $utf8;
    //return decodeURIComponent(escape($utf8));
  }

  /*
   public static function encodeURIComponent($str) {
  $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
  return strtr(rawurlencode($str), $revert);
  }
  */
}

function uniord($u) {
  return hexdec(bin2hex($u));
}
