<?php
/*
 * Copyright (C) 2014 - 2017 Takeshi SAKURAI <sakurai@pnop.co.jp>
 *      http://www.pnop.co.jp/
 *
 * Licensed under the Apache License, Version 2.0 (the &quot;License&quot;);
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an &quot;AS IS&quot; BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Microsoft Azure Document DB Library for PHP
 *
 * Wrapper class of Document DB REST API
 *
 * @link http://msdn.microsoft.com/en-us/library/azure/dn781481.aspx
 * @version 2.7
 * @author Takeshi SAKURAI <sakurai@pnop.co.jp>
 * @since PHP 5.3
 */
require_once 'HTTP/Request2.php';

class DocumentDBResult
{
    public $status;
    public $header;
    public $body;
    public $data;
    public $db_error;
    public $json_error;
    
    public function __construct(int $status, array $header, string $body, $data, bool $db_error, int $json_error)
    {
        $this->status     = $status;
        $this->header     = $header;
        $this->body       = $body;
        $this->data       = $data;
        $this->db_error   = $db_error;
        $this->json_error = $json_error;
    }
}

class DocumentDBResponse
{
    public $status; // success, fail, error
    public $data;
    public $message;
    public $code;
    
    public function __construct(string $status, $data, string $message = '', int $code = 0)
    {
        $this->status  = $status;
        $this->data    = $data;
        $this->message = $message;
        $this->code    = $code;
    }
}

class ResultCache
{
    private $self;
    private $cache;
    private $enable;
    private $func_list;
    private $func_create;

    public function __construct($self, bool $enable=true, $func_list, $func_create)
    {
        $this->self        = $self;
        $this->enable      = $enable;
        $this->cache       = array();
        $this->func_list   = $func_list   ? $func_list : null;
        $this->func_create = $func_create ? $func_create : null;
    }
    
    public function get($id) : string
    {        
        $result = isset($this->cache[$id]) ? $this->cache[$id] : '';

        if(!$result && $this->func_list)
        {
            $list = call_user_func($this->func_list, $this->self);
            
            foreach($list as $row)
            {
                if ($row['id'] === $id) $result = $row['_rid'];
                if($this->enable) $this->cache[$row['id']] = $row['_rid'];
            }
        }
        
        if(!$result && $this->func_list)
        {
            $result = call_user_func($this->func_create, $this->self, $id);
            if($this->enable) $this->cache[$id] = $result;
        }
        
        return $result;
    }    
}

class DocumentDBDatabase
{
    private $document_db;
    private $rid_db;
    private $cache;

    public function __construct($document_db, $rid_db, $enableCache=false)
    {
        $this->document_db = $document_db;
        $this->rid_db      = $rid_db;
        $this->cache       = new ResultCache($this, $enableCache, ['DocumentDBDatabase', 'listCollections'], ['DocumentDBDatabase', 'createCollection']);
    }
      
   /**
    * listCollections
    *
    * @access public
    */
    public static function listCollections($col) : array
    {
        $response = $col->document_db->listCollections($col->rid_db);    
        if(!isset($response->data['DocumentCollections'])) return $col->document_db->callErrorHandler($response, array()); 
        return $response->data['DocumentCollections'];
    }
     
   /**
    * createCollection
    *
    * @access public
    * @param string $col_name Collection name
    */   
    public static function createCollection($col, $col_name) : string
    {
        $response = $col->document_db->createCollection($col->rid_db, '{"id":"' . $col_name . '"}');
        if(!isset($response->data['_rid'])) return $col->document_db->callErrorHandler($response, ''); 
        return $response->data['_rid'];
    }
    
   /**
    * selectCollection
    *
    * @access public
    * @param string $col_name Collection name
    */
    public function selectCollection($col_name)
    {
        $rid_col = $this->cache->get($col_name);
        if ($rid_col) return new DocumentDBCollection($this->document_db, $this->rid_db, $rid_col);
        else          return $this->document_db->callErrorHandler(null, null);
    }
}

class DocumentDBCollection
{
  private $document_db;
  private $rid_db;
  private $rid_col;

  /**
   * __construct
   *
   * @access public
   * @param DocumentDB $document_db DocumentDB object
   * @param string $rid_db          Database ID
   * @param string $rid_col         Collection ID
   */
  public function __construct($document_db, $rid_db, $rid_col)
  {
    $this->document_db = $document_db;
    $this->rid_db      = $rid_db;
    $this->rid_col     = $rid_col;
  }

  /**
   * query
   * @access public
   * @param string $query Query
   * @return string JSON strings
   */
  public function query($query)
  {
    return $this->document_db->query($this->rid_db, $this->rid_col, $query);
  }

  /**
   * createDocument
   *
   * @access public
   * @param string $json    JSON formatted document
   * @param bool   $upsert  true: create or update the document, false(default): create if not exists
   * @return string JSON strings
   */
  public function createDocument($json, $upsert=false)
  {
    return $this->document_db->createDocument($this->rid_db, $this->rid_col, $json, $upsert);
  }
  
  /**
   * listDocuments
   *
   * @access public
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listDocuments($if_none_match=null)
  {
    return $this->document_db->listDocuments($this->rid_db, $this->rid_col, $if_none_match);
  }
  
  /**
   * getDocument
   *
   * @access public
   * @param string $rid_doc             Resource Doc ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getDocument($rid_doc, $if_none_match=null)
  {
    return $this->document_db->getDocument($this->rid_db, $this->rid_col, $rid_doc, $if_none_match);
  }
  
  /**
   * replaceDocument
   *
   * @access public
   * @param string $rid_doc         Resource Doc ID
   * @param string $json            JSON request
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceDocument($rid_doc, $json, $if_match_etag=null)
  {
    return $this->document_db->replaceDocument($this->rid_db, $this->rid_col, $rid_doc, $json, $if_match_etag);
  }

  /**
   * deleteDocument
   *
   * @access public
   * @param  string $rid            document ResourceID (_rid)
   * @param  string $if_match_etag  Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON strings
   */
  public function deleteDocument($rid, $if_match_etag=null)
  {
    return $this->document_db->deleteDocument($this->rid_db, $this->rid_col, $rid, $if_match_etag);
  }

/*
  public function createUser($json)
  {
    return $this->document_db->createUser($this->rid_db, $json);
  }

  public function listUsers()
  {
    return $this->document_db->listUsers($this->rid_db, $rid);
  }

  public function deletePermission($uid, $pid)
  {
    return $this->document_db->deletePermission($this->rid_db, $uid, $pid);
  }

  public function listPermissions($uid)
  {
    return $this->document_db->listPermissions($this->rid_db, $uid);
  }

  public function getPermission($uid, $pid)
  {
    return $this->document_db->getPermission($this->rid_db, $uid, $pid);
  }
*/
  public function listStoredProcedures()
  {
    return $this->document_db->listStoredProcedures($this->rid_db, $this->rid_col);
  }
  public function executeStoredProcedure($sproc_name, $json)
  {
    return $this->document_db->executeStoredProcedure($this->rid_db, $this->rid_col, $sproc_name, $json);
  }
  public function createStoredProcedure($json)
  {
    return $this->document_db->createStoredProcedure($this->rid_db, $this->rid_col, $json);
  }
  public function replaceStoredProcedure($sproc_name, $json)
  {
    return $this->document_db->replaceStoredProcedure($this->rid_db, $this->rid_col, $sproc_name, $json);
  }
  public function deleteStoredProcedure($sproc_name)
  {
    return $this->document_db->deleteStoredProcedure($this->rid_db, $this->rid_col, $sporc_name);
  }

  public function listUserDefinedFunctions()
  {
    return $this->document_db->listUserDefinedFunctions($this->rid_db, $this->rid_col);
  }
  public function createUserDefinedFunction($json)
  {
    return $this->document_db->createUserDefinedFunction($this->rid_db, $this->rid_col, $json);
  }
  public function replaceUserDefinedFunction($udf, $json)
  {
    return $this->document_db->replaceUserDefinedFunction($this->rid_db, $this->rid_col, $udf, $json);
  }
  public function deleteUserDefinedFunction($udf)
  {
    return $this->document_db->deleteUserDefinedFunction($this->rid_db, $this->rid_col, $udf);
  }

  public function listTriggers()
  {
    return $this->document_db->listTriggers($this->rid_db, $this->rid_col);
  }
  public function createTrigger($json)
  {
    return $this->document_db->createTrigger($this->rid_db, $this->rid_col, $json);
  }
  public function replaceTrigger($trigger, $json)
  {
    return $this->document_db->replaceTrigger($this->rid_db, $this->rid_col, $trigger, $json);
  }
  public function deleteTrigger($trigger)
  {
    return $this->document_db->deleteTrigger($this->rid_db, $this->rid_col, $trigger);
  }

}

class DocumentDB
{
  private $host;
  private $master_key;
  private $error_handler  = ['DocumentDB','defaultErrorHandler'];
  private $request_charge = 0;
  private $enableCache    = false;
  private $cache;

  /**
    * __construct
    *
    * @access public
    * @param string   $host            URI of Key
    * @param string   $master_key      Primary or Secondary key
    * @param array    $options         Configuration
    *        callable  error_handler   Function to handle errors
    *        bool      enableCache     Enable result caching
    */
    public function __construct($host, $master_key, $options=array())
    {
        $this->host          = $host;
        $this->master_key    = $master_key;

        $available_options = array('enableCache', 'error_handler');
        foreach ($available_options as $name) {
            if (isset($options[$name])) {
                $this->$name = $options[$name];
            }
        }
        
        $this->cache = new ResultCache($this, $this->enableCache, ['DocumentDB','_listDatabases'], ['DocumentDB','_createDatabase']);
    }

  /**
   * getAuthHeaders
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn783368.aspx
   * @access private
   * @param string $verb          Request Method (GET, POST, PUT, DELETE)
   * @param string $resource_type Resource Type
   * @param string $resource_id   Resource ID
   * @return string Array of Request Headers
   */
  private function getAuthHeaders($verb, $resource_type, $resource_id)
  {
    $x_ms_date = gmdate('D, d M Y H:i:s T', strtotime('+2 minutes'));
    $master = 'master';
    $token = '1.0';
    $x_ms_version = '2017-02-22';

    $key = base64_decode($this->master_key);
    $string_to_sign = $verb . "\n" .
                      $resource_type . "\n" .
                      $resource_id . "\n" .
                      $x_ms_date . "\n" .
                      "\n";

    $sig = base64_encode(hash_hmac('sha256', strtolower($string_to_sign), $key, true));

    return Array(
             'Accept: application/json',
             'User-Agent: documentdb.php.sdk/1.0.0',
             'Cache-Control: no-cache',
             'x-ms-date: ' . $x_ms_date,
             'x-ms-version: ' . $x_ms_version,
             'authorization: ' . urlencode("type=$master&ver=$token&sig=$sig")
           );
  }

  /**
   * defaultErrorHandler
   *
   * @access public
   * @param object $response      Response Data
   * @return object Modified response Data
   */
  public static function defaultErrorHandler($response)
  {
    var_dump($response);
    exit;
  }
  
 /**
   * callErrorHandler
   *
   * @access public
   * @param varargs $params  Arguments to pass to the error handler
   * @return object Modified response Data
   */
  public function callErrorHandler(...$params)
  {
      return call_user_func_array($this->error_handler, $params);
  }
  
  /**
   * checkForErrors
   *
   * @access private
   * @param string $action   Action executed
   * @param string $response Response
   * @return bool error
   */
  private function checkForErrors(string $action, int $http_status_code)
  {
      $error = false;
      
           if($action == 'getInfo')                      $error = ($http_status_code != 200); 
      else if (0 === strpos($action, 'query'))           $error = ($http_status_code != 200); 
      else if (0 === strpos($action, 'list'))            $error = ($http_status_code != 200 && $http_status_code != 304); 
      else if (0 === strpos($action, 'get'))             $error = ($http_status_code != 200 && $http_status_code != 304                                       
                                                                                            && $http_status_code != 404); 
      else if (0 === strpos($action, 'create'))          $error = ($http_status_code != 201 && $http_status_code != 409);
      else if (0 === strpos($action, 'replace'))         $error = ($http_status_code != 200 && $http_status_code != 404                                       
                                                                                            && $http_status_code != 409                                       
                                                                                            && $http_status_code != 412);
      else if (0 === strpos($action, 'delete'))          $error = ($http_status_code != 204 && $http_status_code != 404                                       
                                                                                            && $http_status_code != 412); 
      else if (0 === strpos($action, 'execute'))         $error = ($http_status_code != 200);
      else                                               $error = ($http_status_code < 200  || $http_status_code  > 409);
      
      return $error;
  }

  /**
   * request
   *
   * use cURL functions
   *
   * @access private
   * @param string $path    request path
   * @param string $method  request method
   * @param array  $headers request headers
   * @param string $body    request body (JSON or QUERY)
   * @return string JSON response
   */
    private function request($path, $method, $headers, $body = NULL)
    {
        $request = new Http_Request2($this->host . $path);

        $request->setHeader($headers);

        if ($method === "GET") {
            $request->setMethod(HTTP_Request2::METHOD_GET);
        } else if ($method === "POST") {
            $request->setMethod(HTTP_Request2::METHOD_POST);
        } else if ($method === "PUT") {
            $request->setMethod(HTTP_Request2::METHOD_PUT);
        } else if ($method === "DELETE") {
            $request->setMethod(HTTP_Request2::METHOD_DELETE);
        }
        
        if ($body) {
            $request->setBody($body);
        }
        
        $error = false;
        
        try
        {
            $http_response = $request->send();  

            $body       = $http_response->getBody();
            $data       = !empty($body) ? json_decode($body, true) : null;
            $json_error = !empty($body) ? json_last_error()        : JSON_ERROR_NONE;
            
            $result   = new DocumentDBResult(
                                $http_response->getStatus(), 
                                $http_response->getHeader(), 
                                $body,
                                $data,
                                $this->checkForErrors(debug_backtrace()[1]['function'], $http_response->getStatus()),
                                $json_error
                            );
                            
            $this->request_charge += ($http_response->getHeader('x-ms-request-charge') ? $http_response->getHeader('x-ms-request-charge') : 0);
                            
            $response = new DocumentDBResponse('success', $result);
        }
        catch (HttpException $ex)
        {
            $result   = null;
            $response = new DocumentDBResponse('error', $ex, $ex->getMessage(), $ex->getCode());
        }

        if($response->status != 'success' || $response->data->db_error || $response->data->json_error)
            $result = $this->callErrorHandler($response, $result);

        return $result;
    }
    
   /**
    * getCharge
    *
    * @return String Total RU consumed
    */
    public function getCharge()
    {
        return $this->request_charge;
    }

   /**
    * selectDB
    *
    * @access public
    * @param string $db_name Database name
    * @return DocumentDBDatabase class
    */
    public function selectDB($db_name)
    {
        $rid_db   = $this->cache->get($db_name);
        
        if ($rid_db) return new DocumentDBDatabase($this, $rid_db);
        else         $this->callErrorHandler(null, null);
    }
  
   /**
    * selectCollection
    *
    * @access public
    * @param string $db_name     Database name
    * @param string $col_name Collection name
    * @return DocumentDBCollection class
    */
    public function selectCollection(string $db_name, string $col_name)
    {
        $db = $this->selectDB($db_name);
        
        return $db ? $db->selectCollection($col_name) : null;
    }

  /**
   * getInfo
   *
   * @access public
   * @return string JSON response
   */
  public function getInfo()
  {
    $headers = $this->getAuthHeaders('GET', '', '');
    $headers[] = 'Content-Length:0';
    return $this->request("", "GET", $headers);
  }

  /**
   * query
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn783363.aspx
   * @access public
   * @param string $rid_id  Resource ID
   * @param string $rid_col Resource Collection ID
   * @param string $query   Query
   * @return string JSON response
   */
  public function query($rid_id, $rid_col, $query)
  {
    $headers = $this->getAuthHeaders('POST', 'docs', $rid_col);
    $headers[] = 'Content-Length:' . strlen($query);
    $headers[] = 'Content-Type:application/sql';
    $headers[] = 'x-ms-max-item-count:-1';
    $headers[] = 'x-ms-documentdb-isquery:True';
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs", "POST", $headers, $query);
  }

  /**
   * listDatabases
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803945.aspx
   * @access public
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listDatabases($if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'dbs', '');
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs", "GET", $headers);
  }
  
  /**
   * _listDatabases
   *
   * @access public
   * @return array databases
   */
    public static function _listDatabases(DocumentDB $db) : array
    {
        $response = $db->listDatabases();
        if(!isset($response->data['Databases'])) return $db->callErrorHandler($response, array()); 
        else return $response->data['Databases'];
    }

  /**
   * getDatabase
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803937.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getDatabase($rid_id, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'dbs', $rid_id);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id, "GET", $headers);
  }

  /**
   * createDatabase
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803954.aspx
   * @access public
   * @param string $json JSON request
   * @return string JSON response
   */
  public function createDatabase($json)
  {
    $headers = $this->getAuthHeaders('POST', 'dbs', '');
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs", "POST", $headers, $json);
  } 

  /**
   * createDatabase
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803954.aspx
   * @access public
   * @param string $json JSON request
   * @return string JSON response
   */
    public static function _createDatabase(DocumentDB $db, string $db_name) : string
    {
        $response = $db->createDatabase('{"id":"' . $db_name . '"}');
        if(!isset($response->data['_rid'])) return $db->callErrorHandler($response, ''); 
        return $response->data['_rid'];
    }

  /**
   * replaceDatabase
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803943.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $json            JSON request
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceDatabase($rid_id, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'dbs', $rid_id);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id, "PUT", $headers, $json);
  }

  /**
   * deleteDatabase
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803942.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteDatabase($rid_id, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'dbs', $rid_id);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id, "DELETE", $headers);
  }

  /**
   * listUsers
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803958.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listUsers($rid_id, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'users', $rid_id);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/users", "GET", $headers);
  }

  /**
   * getUser
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $rid_user            Resource User ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getUser($rid_id, $rid_user, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'users', $rid_user);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user, "GET", $headers);
  }

  /**
   * createUser
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803946.aspx
   * @access public
   * @param string $rid_id Resource ID
   * @param string $json   JSON request
   * @return string JSON response
   */
  public function createUser($rid_id, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'users', $rid_id);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/users", "POST", $headers, $json);
  }

  /**
   * replaceUser
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803941.aspx
   * @access public
   * @param string $rid_id   Resource ID
   * @param string $rid_user Resource User ID
   * @param string $json     JSON request
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceUser($rid_id, $rid_user, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'users', $rid_user);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user, "PUT", $headers, $json);
  }

  /**
   * deleteUser
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803953.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_user        Resource User ID
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteUser($rid_id, $rid_user, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'users', $rid_user);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user, "DELETE", $headers);
  }

  /**
   * listCollections
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803935.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listCollections($rid_id, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'colls', $rid_id);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls", "GET", $headers);
  }

  /**
   * getCollection
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803951.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $rid_col             Resource Collection ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getCollection($rid_id, $rid_col, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'colls', $rid_col);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col, "GET", $headers);
  }

  /**
   * createCollection
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803934.aspx
   * @access public
   * @param string $rid_id Resource ID
   * @param string $json   JSON request
   * @return string JSON response
   */
  public function createCollection($rid_id, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'colls', $rid_id);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/colls", "POST", $headers, $json);
  }

  /**
   * deleteCollection
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803953.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteCollection($rid_id, $rid_col, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'colls', $rid_col);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col, "DELETE", $headers);
  }

  /**
   * listDocuments
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803955.aspx
   * @access public
   * @param string $rid_id Resource     ID
   * @param string $rid_colResource     Collection ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listDocuments($rid_id, $rid_col, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'docs', $rid_col);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs", "GET", $headers);
  }

  /**
   * getDocument
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803957.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $rid_col             Resource Collection ID
   * @param string $rid_doc             Resource Doc ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getDocument($rid_id, $rid_col, $rid_doc, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'docs', $rid_doc);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    $options = array(
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_HTTPGET => true,
    );
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc, "GET", $headers);
  }

  /**
   * createDocument
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803948.aspx
   * @access public
   * @param string $rid_id  Resource ID
   * @param string $rid_col Resource Collection ID
   * @param string $json    JSON request
   * @param bool   $upsert  true: create or update the document, false(default): create if not exists
   * @return string JSON response
   */
  public function createDocument($rid_id, $rid_col, $json, $upsert=false)
  {
    $headers = $this->getAuthHeaders('POST', 'docs', $rid_col);
    $headers[] = 'Content-Length:' . strlen($json);
    if($upsert) $headers[] = 'x-ms-documentdb-is-upsert:True';
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs", "POST", $headers, $json);
  }

  /**
   * replaceDocument
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803947.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_doc         Resource Doc ID
   * @param string $json            JSON request
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceDocument($rid_id, $rid_col, $rid_doc, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'docs', $rid_doc);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc, "PUT", $headers, $json);
  }

  /**
   * deleteDocument
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803952.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_doc         Resource Doc ID
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteDocument($rid_id, $rid_col, $rid_doc, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'docs', $rid_doc);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc, "DELETE", $headers);
  }

  /**
   * listAttachments
   *
   * @link http://
   * @access public
   * @param string $rid_id Resource     ID
   * @param string $rid_colResource     Collection ID
   * @param string $rid_doc             Resource Doc ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listAttachments($rid_id, $rid_col, $rid_doc, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'attachments', $rid_doc);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc . "/attachments", "GET", $headers);
  }

  /**
   * getAttachment
   *
   * @link http://
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $rid_col             Resource Collection ID
   * @param string $rid_doc             Resource Doc ID
   * @param string $rid_at              Resource Attachment ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getAttachment($rid_id, $rid_col, $rid_doc, $rid_at, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'attachments', $rid_at);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc . "/attachments/" . $rid_at, "GET", $headers);
  }

  /**
   * createAttachment
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
   * @access public
   * @param string $rid_id       Resource ID
   * @param string $rid_col      Resource Collection ID
   * @param string $rid_doc      Resource Doc ID
   * @param string $content_type Content-Type of Media
   * @param string $filename     Attachement file name
   * @param string $file         URL encoded Attachement file (Raw Media)
   * @return string JSON response
   */
  public function createAttachment($rid_id, $rid_col, $rid_doc, $content_type, $filename, $file)
  {
    $headers = $this->getAuthHeaders('POST', 'attachments', $rid_doc);
    $headers[] = 'Content-Length:' . strlen($file);
    $headers[] = 'Content-Type:' . $content_type;
    $headers[] = 'Slug:' . urlencode($filename);
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc . "/attachments", "POST", $headers, $file);
  }

  /**
   * replaceAttachment
   *
   * @link http://
   * @access public
   * @param string $rid_id       Resource ID
   * @param string $rid_col      Resource Collection ID
   * @param string $rid_doc      Resource Doc ID
   * @param string $rid_at       Resource Attachment ID
   * @param string $content_type Content-Type of Media
   * @param string $filename     Attachement file name
   * @param string $file         URL encoded Attachement file (Raw Media)
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceAttachment($rid_id, $rid_col, $rid_doc, $rid_at, $content_type, $filename, $file, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'attachments', $rid_at);
    $headers[] = 'Content-Length:' . strlen($file);
    $headers[] = 'Content-Type:' . $content_type;
    $headers[] = 'Slug:' . urlencode($filename);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc . "/attachments/" . $rid_at, "PUT", $headers, $file);
  }

  /**
   * deleteAttachment
   *
   * @link http://
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_doc         Resource Doc ID
   * @param string $rid_at          Resource Attachment ID
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteAttachment($rid_id, $rid_col, $rid_doc, $rid_at, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'attachments', $rid_at);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/docs/" . $rid_doc . "/attachments/" . $rid_at, "DELETE", $headers);
  }

  /**
   * listOffers
   *
   * @link http://
   * @access public
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listOffers($if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'offers', '');
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/offers", "GET", $headers);
  }

  /**
   * getOffer
   *
   * @link http://
   * @access public
   * @param string $rid                 Resource ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getOffer($rid, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'offers', $rid);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/offers/" . $rid, "GET", $headers);
  }

  /**
   * replaceOffer
   *
   * @link http://
   * @access public
   * @param string $rid  Resource ID
   * @param string $json    JSON request
   * @return string JSON response
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   */
  public function replaceOffer($rid, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'offers', $rid);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/offers/" . $rid, "PUT", $headers, $json);
  }

  /**
   * queryingOffers
   *
   * @link http://
   * @access public
   * @param string $json    JSON request
   * @return string JSON response
   */
  public function queryingOffers($json)
  {
    $headers = $this->getAuthHeaders('POST', 'offers', '');
    $headers[] = 'Content-Length:' . strlen($json);
    $headers[] = 'Content-Type:application/query+json';
    $headers[] = 'x-ms-documentdb-isquery:True';
    return $this->request("/offers", "POST", $headers, $json);
  }

  /**
   * listPermissions
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $rid_user            Resource User ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listPermissions($rid_id, $rid_user, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'permissions', $rid_user);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user . "/permissions", "GET", $headers);
  }

  /**
   * createPermission
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803946.aspx
   * @access public
   * @param string $rid_id Resource ID
   * @param string $rid_user Resource User ID
   * @param string $json   JSON request
   * @return string JSON response
   */
  public function createPermission($rid_id, $rid_user, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'permissions', $rid_user);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user . "/permissions", "POST", $headers, $json);
  }

  /**
   * getPermission
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
   * @access public
   * @param string $rid_id              Resource ID
   * @param string $rid_user            Resource User ID
   * @param string $rid_permission      Resource Permission ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function getPermission($rid_id, $rid_user, $rid_permission, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'permissions', $rid_permission);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user . "/permissions/" . $rid_permission, "GET", $headers);
  }

  /**
   * replacePermission
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_user        Resource User ID
   * @param string $rid_permission  Resource Permission ID
   * @param string $json            JSON request
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replacePermission($rid_id, $rid_user, $rid_permission, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'permissions', $rid_permission);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user . "/permissions/" . $rid_permission, "PUT", $headers, $json);
  }

  /**
   * deletePermission
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_user        Resource User ID
   * @param string $rid_permission  Resource Permission ID
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deletePermission($rid_id, $rid_user, $rid_permission, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'permissions', $rid_permission);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/users/" . $rid_user . "/permissions/" . $rid_permission, "DELETE", $headers);
  }

  /**
   * listStoredProcedures
   *
   * @link http://
   * @access public
   * @param string $rid_id Resource     ID
   * @param string $rid_colResource     Collection ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listStoredProcedures($rid_id, $rid_col, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'sprocs', $rid_col);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/sprocs", "GET", $headers);
  }

  /**
   * executeStoredProcedure
   *
   * @link http://
   * @access public
   * @param string $rid_id  Resource ID
   * @param string $rid_col Resource Collection ID
   * @param string $rid_sproc  Resource ID of Stored Procedurea
   * @param string $json    Parameters
   * @return string JSON response
   */
  public function executeStoredProcedure($rid_id, $rid_col, $rid_sproc, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'sprocs', $rid_sproc);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/sprocs/" . $rid_sproc, "POST", $headers, $json);
  }

  /**
   * createStoredProcedure
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
   * @access public
   * @param string $rid_id       Resource ID
   * @param string $rid_col      Resource Collection ID
   * @param string $json         JSON of function
   * @return string JSON response
   */
  public function createStoredProcedure($rid_id, $rid_col, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'sprocs', $rid_col);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/sprocs", "POST", $headers, $json);
  }

  /**
   * replaceStoredProcedure
   *
   * @link http://
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_sproc       Resource ID of Stored Procedurea
   * @param string $json            Parameters
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceStoredProcedure($rid_id, $rid_col, $rid_sproc, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'sprocs', $rid_sproc);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/sprocs/" . $rid_sproc, "PUT", $headers, $json);
  }

  /**
   * deleteStoredProcedure (MethodNotAllowed: MUST FIX)
   *
   * @link http://
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_sproc       Resource ID of Stored Procedurea
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteStoredProcedure($rid_id, $rid_col, $rid_sproc, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'sprocs', $rid_sproc);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/sprocs/" . $rid_sproc, "DELETE", $headers);
  }

  /**
   * listUserDefinedFunctions
   *
   * @link http://
   * @access public
   * @param string $rid_id Resource     ID
   * @param string $rid_colResource     Collection ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listUserDefinedFunctions($rid_id, $rid_col, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'udfs', $rid_col);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/udfs", "GET", $headers);
  }

  /**
   * createUserDefinedFunction
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
   * @access public
   * @param string $rid_id       Resource ID
   * @param string $rid_col      Resource Collection ID
   * @param string $json         JSON of function
   * @return string JSON response
   */
  public function createUserDefinedFunction($rid_id, $rid_col, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'udfs', $rid_col);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/udfs", "POST", $headers, $json);
  }

  /**
   * replaceUserDefinedFunction
   *
   * @link http://
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_udf         Resource ID of User Defined Function
   * @param string $json            Parameters
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceUserDefinedFunction($rid_id, $rid_col, $rid_udf, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'udfs', $rid_udf);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/udfs/" . $rid_udf, "PUT", $headers, $json);
  }

  /**
   * deleteUserDefinedFunction
   *
   * @link http://
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_udf         Resource ID of User Defined Function
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteUserDefinedFunction($rid_id, $rid_col, $rid_udf, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'udfs', $rid_udf);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/udfs/" . $rid_udf, "DELETE", $headers);
  }

  /**
   * listTriggers
   *
   * @link http://
   * @access public
   * @param string $rid_id Resource     ID
   * @param string $rid_colResource     Collection ID
   * @param string $if_none_match       Returns the ressource if the server ETag value does not match request ETag value, else "304 Not modified"
   * @return string JSON response
   */
  public function listTriggers($rid_id, $rid_col, $if_none_match=null)
  {
    $headers = $this->getAuthHeaders('GET', 'triggers', $rid_col);
    $headers[] = 'Content-Length:0';
    if($if_none_match != null) $headers[] = 'If-None-Match:'.$if_none_match;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/triggers", "GET", $headers);
  }

  /**
   * createTrigger
   *
   * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
   * @access public
   * @param string $rid_id       Resource ID
   * @param string $rid_col      Resource Collection ID
   * @param string $json         JSON of function
   * @return string JSON response
   */
  public function createTrigger($rid_id, $rid_col, $json)
  {
    $headers = $this->getAuthHeaders('POST', 'triggers', $rid_col);
    $headers[] = 'Content-Length:' . strlen($json);
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/triggers", "POST", $headers, $json);
  }

  /**
   * replaceTrigger
   *
   * @link http://
   * @access public
   * @param string $rid_id       Resource ID
   * @param string $rid_col      Resource Collection ID
   * @param string $rid_trigger      Resource ID of Trigger
   * @param string $json    Parameters
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function replaceTrigger($rid_id, $rid_col, $rid_trigger, $json, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('PUT', 'triggers', $rid_trigger);
    $headers[] = 'Content-Length:' . strlen($json);
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/triggers/" . $rid_trigger, "PUT", $headers, $json);
  }

  /**
   * deleteTrigger
   *
   * @link http://
   * @access public
   * @param string $rid_id          Resource ID
   * @param string $rid_col         Resource Collection ID
   * @param string $rid_trigger     Resource ID of Trigger
   * @param string $if_match_etag   Resource is updated if server ETag value matches request ETag value, else operation is rejected with "HTTP 412 Precondition failure"
   * @return string JSON response
   */
  public function deleteTrigger($rid_id, $rid_col, $rid_trigger, $if_match_etag=null)
  {
    $headers = $this->getAuthHeaders('DELETE', 'triggers', $rid_trigger);
    $headers[] = 'Content-Length:0';
    if($if_match_etag != null) $headers[] = 'If-Match:'.$if_match_etag;
    return $this->request("/dbs/" . $rid_id . "/colls/" . $rid_col . "/triggers/" . $rid_trigger, "DELETE", $headers);
  }

}

