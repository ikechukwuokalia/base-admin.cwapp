<?php
namespace IO;

use stdClass;
use \TymFrontiers\Data,
    \TymFrontiers\MySQLDatabase,
    \TymFrontiers\InstanceError,
    \TymFrontiers\Validator,
    TymFrontiers\MultiForm;

class Admin{
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='code';
  protected static $_db_name;
  protected static $_table_name = "users";
	protected static $_db_fields = ["code", "status", "user", "name", "surname", "email", "phone", "password", "work_group", "country_code", "_author", "_created"];
  protected static $_prop_type = [];
  protected static $_prop_size = [];
  protected static $_prefix_code = "052";
  protected static $_server_name;

  private $code;
  protected $status = "ACTIVE";
  public $user = NULL;
  public $name;
  public $surname;
  public $email;
  public $phone;
  public $password;
  public $work_group = "USER";
  public $country_code;

  protected $_author;
  protected $_created;

  public $errors = [];

  function __construct($conn = false) {
    if (!self::$_server_name = get_constant("PRJ_SERVER_NAME")) throw new \Exception("Server-name constant was not defined", 1);
    
    if (!self::$_db_name = get_database(self::$_server_name, "admin")) throw new \Exception("[admin] type database not set for server [" .self::$_server_name . "]", 1);
    global $database;
    $conn = $conn && $conn instanceof MySQLDatabase ? $conn : ($database && $database instanceof MySQLDatabase ? $database : false);
    $conn = query_conn(self::$_server_name, $conn);
    self::_setConn($conn);
  }

  public static function authenticate(string $code, string $password){
    global $database;
    global $access_ranks;
    self::$_server_name = get_constant("PRJ_SERVER_NAME");
    $conn = query_conn(self::$_server_name, $database);
    self::_setConn($conn);
    self::$_db_name = get_database(self::$_server_name, "admin");

    $data = new Data();
    $password = $conn->escapeValue($password);
    $valid = new Validator;
    $prefix = self::$_prefix_code;
    $file_db = get_database(self::$_server_name, "file");
    $file_tbl = "file_meta";
    $file_server = get_constant("PRJ_FILE_SERVER");

    if (!$code = $valid->pattern($code, ["code","pattern", "/^{$prefix}([0-9]{4,4})([0-9]{4,4})$/"])) return false;
    $sql = "SELECT adm.`code`, adm.`status`, adm.work_group, adm.password, adm.name, adm.surname, adm.email, adm.phone, adm.country_code,
                  (
                    SELECT CONCAT('{$file_server}/',f._name)
                  ) AS avatar
            FROM :db:.:tbl: AS adm
            LEFT JOIN `{$file_db}`.`file_default` AS fd ON fd.`user` = adm.`code` AND fd.set_key = 'USER.AVATAR'
            LEFT JOIN `{$file_db}`.`{$file_tbl}` AS f ON f.id = fd.file_id
            WHERE adm.`status` IN('ACTIVE','PENDING') 
            AND adm.`code` = '{$code}'
            AND adm.password IS NOT NULL
            LIMIT 1";
    $result_array = self::findBySql($sql);
    $record = !empty($result_array) ? $data->pwdCheck($password,$result_array[0]->password) : false;
    if( $record && $user = $result_array[0]->profile()){
      // $user = $user[0];
      $usr = new \StdClass();
      $user->avatar = !empty($result_array[0]->avatar) ? $result_array[0]->avatar : $file_server . "/app/ikechukwuokalia/admin.cwapp/img/default-avatar.png";
      $usr->code = $usr->uniqueid = $user->code;
      $usr->access_group = $user->work_group;
      $usr->access_rank = $access_ranks[$user->work_group];
      $usr->name = $user->name;
      $usr->surname = $user->surname;
      $usr->status = $user->status;
      $usr->avatar = $user->avatar;
      $usr->country_code = $user->country_code;
      return $usr;
    }
    return false;
  }

  public function isActive(bool $strict = false){
    if( $strict ){
      return !empty($this->_id) && \in_array($this->status,['ACTIVE']);
    }else{
      return !empty($this->_id) && \in_array($this->status,['ACTIVE','PENDING']);
    }
    return false;
  }
  public function register(string $work_group, string $user, string $code = ""){
    $conn =& self::$_conn;

    $data = new Data();
    $unset = [];
    foreach ($this->_req_params as $prop) {
      if (empty($user[$prop])) $unset[] = $prop;
    }
    if (!empty($unset)) {
      $this->errors["_createNew"][] = [
        @$GLOBALS['access_ranks']['DEVELOPER'],
        256,
        "Required properties [". \implode(", ", $unset) . "] not set", __FILE__,
        __LINE__
      ];
      return false;
    }
    foreach($user as $key=>$val){
      if( \property_exists(__CLASS__, $key) && !empty($val) ){
        $this->$key = $conn->escapeValue($val);
      }
    }
    if ($verified) $this->status = "ACTIVE";
    global $code_prefix;
    if (empty($this->code)) {
      if (!\is_array($code_prefix) || empty($code_prefix["profile"]) ) {
        $this->errors["_createNew"][] = [
          @$GLOBALS['access_ranks']['DEVELOPER'],
          256,
          "'code_prefix' variable not set as array.", __FILE__,
          __LINE__
        ];
        return false;
      }
      $prfx = $code_prefix["profile"];
      $this->code = generate_code($prfx, Data::RAND_NUMBERS, 11, $this, "code", true);
    }
    $this->password = $data->pwdHash($this->password);
    // get user connection
    if( $this->_create($conn) ){
      $this->password = null;
      return true;
    } else {
      $this->code = null;
      $this->errors['self'][] = [0,256, "Request failed at this this time.",__FILE__, __LINE__];
      if( \class_exists('\TymFrontiers\InstanceError') ){
        $ex_errors = new \TymFrontiers\InstanceError($conn);
        if( !empty($ex_errors->errors) ){
          foreach( $ex_errors->get("",true) as $key=>$errs ){
            foreach($errs as $err){
              $this->errors['self'][] = [0,256, $err,__FILE__, __LINE__];
            }
          }
        }
      }
    }
    return false;
  }
  public function requestAccount (string $user, string $password):bool {
    $this->status = "REQUESTING";
    $this->work_group = "USER";
    $valid = new Validator;
    if (!$this->user = $valid->pattern($user, ["user","pattern", "/^(252|352)([\d]{4,4})([\d]{4,4})$/"])) {
      if ($errs = (new InstanceError($valid))->get("pattern", true)) {
        unset($valid->errors["pattern"]);
        foreach ($errs as $er) {
          $this->errors["requestAccount"][] = [0, 256, $er, __FILE__, __LINE__];
        }
      }
    } else if (!$password = $valid->password($password, ["password","password"])) {
      if ($errs = (new InstanceError($valid))->get("password", true)) {
        unset($valid->errors["password"]);
        foreach ($errs as $er) {
          $this->errors["requestAccount"][] = [0, 256, $er, __FILE__, __LINE__];
        }
      }
    } else {
      // get ready to create
      $this->password = Data::pwdHash($password);
      $this->code = generate_code(self::CODE_PREFIX, Data::RAND_NUMBERS, 11, $this, "code", true);
      $utype = code_storage($user, "BASE");
      if ($utype && $ustatus = (new MultiForm($utype[0], $utype[1], $utype[2]))->findById($user)) {
        if ($ustatus->status == "ACTIVE") {
          $this->name = @ $ustatus->name;
          $this->surname = @ $ustatus->surname;
          return $this->_create();
        } else {
          $this->errors["requestAccount"][] = [0, 256, "[user] profile is not active.", __FILE__, __LINE__];
        }
      } else {
        $this->errors["requestAccount"][] = [0, 256, "[user] profile was not found.", __FILE__, __LINE__];
      }
    }
    return false;
  }

  final public function prefix_code (string $code = ""):string|null {
    if (!empty($code)) self::$_prefix_code = $code;
    return self::$_prefix_code;
  }
  final public function server_name (string $server_code = ""):string|null {
    if (!empty($server_code)) self::$_server_name = $server_code;
    return self::$_server_name;
  }
  final public function profile () {
    if (!empty($this->code) && !empty($this->name)) {
      $usr = new stdClass();
      $usr->code = $this->code();
      $usr->status = $this->status();
      $usr->work_group = $this->work_group;
      $usr->name = $this->name;
      $usr->surname = $this->surname;
      $usr->email = $this->email;
      $usr->phone = $this->phone;
      $usr->country_code = $this->country_code;
      return $usr;
    }
    return null;
  }
  final public function code () { return $this->code; }
  final public function status () { return $this->status; }
  final public function author () { return $this->_author; }
  final public function delete () { return false; }
  final public function update () { return false; }
  final public function create () { return false; }
}