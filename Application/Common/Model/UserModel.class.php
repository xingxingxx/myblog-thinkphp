<?php
/**
 * Created by PhpStorm.
 * User: 肖兴平
 * Email: xx9815@qq.com
 * Date: 2015/5/25
 * Time: 16:54
 */
namespace Common\Model;
use Think\Model;

/**
 * 用户基础模型
 */
class UserModel extends Model{
    /* 用户模型自动验证 */
    protected $_validate = array(
        /* 验证用户名 */
        array('username', 'require','用户名不为空' , self::EXISTS_VALIDATE), //用户名不为空
        array('username', '/^[\S]{3,30}$/','用户名不合法' , self::EXISTS_VALIDATE), //用户名不合法
        array('username', '', '用户名被占用', self::EXISTS_VALIDATE, 'unique'), //用户名被占用

        /* 验证密码 */
        array('password', 'require','密码不为空' , self::EXISTS_VALIDATE), //密码不为空
        array('password', '/^(?!\D+$)(?![^a-zA-Z]+$)[a-zA-Z0-9]{8,30}$/', '密码不合法', self::EXISTS_VALIDATE), //密码不合法

        /* 验证邮箱 */
        array('email', 'require','邮箱不为空' , self::EXISTS_VALIDATE), //邮箱不为空
        array('email', 'email', '邮箱格式不正确', self::EXISTS_VALIDATE), //邮箱格式不正确
        array('email', '1,32', '邮箱长度不合法', self::EXISTS_VALIDATE, 'length'), //邮箱长度不合法
        array('email', '', '邮箱被占用', self::EXISTS_VALIDATE, 'unique'), //邮箱被占用
    );

    /* 用户模型自动完成 */
    protected $_auto = array(
        array('password', 'think_md5', self::MODEL_BOTH, 'function'),
        array('reg_time', NOW_TIME, self::MODEL_INSERT),
        array('reg_ip', 'get_client_ip', self::MODEL_INSERT, 'function', 1),
    );

    /**
     * 用户登录
     * @param $username
     * @param $password
     * @param $auto
     * @return bool|string
     */
    public function login($username, $password, $auto){
        //查询用户
        $user = $this->field('uid, username, password, status')->where(array('username|email|mobile'=>$username))->find();
        if(is_array($user) && $user['status']){
            // 验证用户密码
            if(think_md5($password) === $user['password']){
                //更新用户登录信息
                $data=array(
                    'uid'=>$user['uid'],
                    'last_login_time'=> NOW_TIME,
                    'last_login_ip'=> get_client_ip(1),
                    'login'=> array('exp', 'login+1'),
                );
                $this->save($data);
                $this->logged($user['uid'], $user['username'], $auto);
                return true;
            } else {
                return '密码错误!';
            }
        } else {
            return '用户不存在或未激活!';
        }
    }

    /**
     * 更改为已登录状态
     * @param $uid
     * @param $username
     * @param $auto 是否自动登录
     */
     public function logged($uid, $username, $auto){
        //更新用户登录信息
        $data = array(
          'uid'=> $uid,
          'last_login_time'=> NOW_TIME,
          'last_login_ip'=> get_client_ip(1),
          'login'=> array('exp', '`login`+1'),
        );
        $this->save($data);
        // 记录登录SESSION和COOKIES
        $auth = array(
            'uid'=> $uid,
            'username'=> $username,
            'last_login_time'=> NOW_TIME,
        );
        //是否自动登录
        if($auto==1){
            $auto=$uid.'|'.$username.'|'.get_client_ip(1);
            $auto=think_base64($auto, 1);//加密
            cookie('web_site_auto',$auto,C('AUTO_LOGIN_TIME'));
        }
        session('user_auth', $auth);
        session('user_auth_sign', data_auth_sign($auth));
     }

    /**
     * 自动登录
     */
    public function autoLogin(){
        $auto =explode('|',think_base64(cookie('web_site_auto')));
        if($auto[2] == get_client_ip(1)){
            $map =array(
                'uid'=>$auto[0],
                'username' =>$auto[1],
                'status'=>1,
            );
            $user= $this->field('uid, username')->where($map)->find();
            if($user){
                $this->logged($user['uid'], $user['username'], 0);
            }
        }
    }

    /**
    * 退出登录
    * @return void
    */
    public function logout(){
       session('user_auth', null);
       session('user_auth_sign', null);
       cookie('web_site_auto', null);
    }

    /**
     * 注册一个新用户
     * @param  string $username 用户名
     * @param  string $password 用户密码
     * @param  string $email    用户邮箱
     * @return integer          注册成功-用户信息，注册失败-错误信息
     */
    public function register($username, $password, $email){
        $token = think_md5($username . time());//创建激活码
        //创建数组
        $data = array(
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'token' => $token,
        );
        if($this->create($data)){
            //邮件内容
            $emailInfo = "亲爱的" . $username . "：<br/>感谢您在我站注册了新帐号。<br/>
            请点击链接激活您的帐号。<br/>
            <a href='".$_SERVER['HTTP_HOST']. U('User/active', array('token'=> $token)). "' target='_blank'>
            ". $_SERVER['HTTP_HOST']. U('User/active', array('token'=> $token)). "</a><br/>
            如果以上链接无法点击，请将它复制到你的浏览器地址栏中进入访问，该链接24小时内有效。";
            //调用邮件发送函数
            $rs=think_send_mail($email, $username, '用户帐号激活', $emailInfo);
            //如果邮件发送成功，则将信息加入数据库
            if($rs===true){
                $uid = $this->add();
                return $uid ? true : '未知错误';
            }else{
                return '邮箱不合法或不存在，请重新填写邮箱。';
            }
        } else {
            return $this->getError(); //获取并返回错误详情
        }
    }

    public function active($token){
        $map = array(
            'token'  => $token,
            'status' => 0,
        );
        $user=$this->field('uid, reg_time')->where($map)->find();
        if($user){
            //从配置中获取激活码有效期
            $token_expire = $user['reg_time'] + C('TOKEN_EXPIRE');
            if(time()>$token_expire){
                return '您的激活有效期已过，请登录您的帐号重新发送激活邮件!';
            }else{
                $user['status']=1;
                if($this->save($user)){
                    return true;
                }
            }
        }else{
            return '未找到需要激活的账号！';
        }
    }
}