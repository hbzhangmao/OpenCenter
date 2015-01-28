<?php
/**
 * 放置用户登陆注册
 */
namespace Ucenter\Controller;


use Think\Controller;
use User\Api\UserApi;

require_once APP_PATH . 'User/Conf/config.php';

/**
 * 用户控制器
 * 包括用户中心，用户登录及注册
 */
class MemberController extends Controller
{

    /**
     * register  注册页面
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function register()
    {
        //获取参数
        $aUsername = I('post.username', '', 'op_t');
        $aNickname = I('post.nickname', '', 'op_t');
        $aPassword = I('post.password', '', 'op_t');
        $aVerify = I('post.verify', '', 'op_t');
        $aRegVerify = I('post.reg_verify', 0, 'intval');
        $aRegType = I('post.reg_type', '', 'op_t');
        $aType = I('get.type', 'start', 'op_t');


        if (!modC('REG_SWITCH', '', 'USERCONFIG')) {
            $this->error('注册已关闭');
        }
        if (IS_POST) { //注册用户

            /* 检测验证码 */
            if (C('VERIFY_OPEN') == 1 or C('VERIFY_OPEN') == 2) {
                if (!check_verify($aVerify)) {
                    $this->error('验证码输入错误。');
                }
            }
            if ($aRegType == 'mobile' || (modC('EMAIL_VERIFY_TYPE', 0, 'USERCONFIG') == 0 && $aRegType == 'email')) {
                if (!D('Verify')->checkVerify($aUsername, $aRegType, $aRegVerify, 0)) {
                    $str = $aRegType == 'mobile' ? '手机' : '邮箱';
                    $this->error($str . '验证失败');
                }
            }
            $aUnType = 0;
            //获取注册类型
            check_username($aUsername, $email, $mobile, $aUnType);
            if ($aRegType == 'email' && $aUnType != 2) {
                $this->error('邮箱格式不正确');
            }
            if ($aRegType == 'mobile' && $aUnType != 3) {
                $this->error('手机格式不正确');
            }

            if (!check_reg_type($aUnType)) {
                $this->error('该类型未开放注册。');
            }

            /* 注册用户 */
            $uid = D('User/UcenterMember')->register($aUsername, $aNickname, $aPassword, $email, $mobile, $aUnType);

            if (0 < $uid) { //注册成功
                $uid = D('User/UcenterMember')->login($aUsername, $aPassword, $aUnType); //通过账号密码取到uid
                D('Member')->login($uid, false); //登陆

                $this->success('', U('Ucenter/member/step2'));
            } else { //注册失败，显示错误信息
                $this->error($this->showRegError($uid));
            }
        } else { //显示注册表单
            if (is_login()) {
                redirect(U('Weibo/Index/index'));
            }

            $regSwitch = modC('REG_SWITCH', '', 'USERCONFIG');
            $regSwitch = explode(',', $regSwitch);
            $this->assign('regSwitch', $regSwitch);
            $this->assign('type', $aType);
            $this->display();
        }
    }

    /* 注册页面step2 */
    public function step2($type = 'upload')
    {
        $type = op_t($type); //显示上传头像页面
        $this->assign('type', $type);
        $this->display('register');
    }

    public function doCropAvatar($crop)
    {
        //调用上传头像接口改变用户的头像
        $result = callApi('User/applyAvatar', array($crop));
        $this->ensureApiSuccess($result);

        //显示成功消息
        $this->success($result['message'], U('Ucenter/member/step3'));
    }

    /* 注册页面step3 */
    public function step3($type = 'finish')
    {
        $type = op_t($type);
        $this->assign('type', $type);
        $this->display('register');
    }

    /* 登录页面 */
    public function login()
    {
        $this->setTitle('用户登录');

        $aUsername = $username = I('post.username', '', 'op_t');
        $aPassword = I('post.password', '', 'op_t');
        $aVerify = I('post.verify', '', 'op_t');
        $aRemember = I('post.remember', 0, 'intval');


        if (IS_POST) { //登录验证
            /* 检测验证码 */
            if (C('VERIFY_OPEN') == 1 or C('VERIFY_OPEN') == 3) {
                if (!check_verify($aVerify)) {
                    $this->error('验证码输入错误。');
                }
            }

            /* 调用UC登录接口登录 */
            check_username($aUsername, $email, $mobile, $aUnType);

            if (!check_reg_type($aUnType)) {
                $this->error('该类型未开放登录。');
            }

            $uid = D('User/UcenterMember')->login($username, $aPassword, $aUnType);
            if (0 < $uid) { //UC登录成功
                /* 登录用户 */
                $Member = D('Member');
                if ($Member->login($uid, $aRemember == 'on')) { //登录用户
                    //TODO:跳转到登录前页面

                    if (UC_SYNC && $uid != 1) {
                        //同步登录到UC
                        $ref = M('ucenter_user_link')->where(array('uid' => $uid))->find();
                        $html = '';
                        $html = uc_user_synlogin($ref['uc_uid']);
                    }

                    $this->success($html, get_nav_url(C('AFTER_LOGIN_JUMP_URL')));
                } else {
                    $this->error($Member->getError());
                }

            } else { //登录失败
                switch ($uid) {
                    case -1:
                        $error = '用户不存在或被禁用！';
                        break; //系统级别禁用
                    case -2:
                        $error = '密码错误！';
                        break;
                    default:
                        $error = $uid;
                        break; // 0-接口参数错误（调试阶段使用）
                }
                $this->error($error);
            }

        } else { //显示登录表单
            if (is_login()) {
                redirect(U('Home/Index/index'));
            }
            $this->display();
        }
    }

    /* 退出登录 */
    public function logout()
    {
        if (is_login()) {
            D('Member')->logout();
            $this->success('退出成功！', U('User/login'));
        } else {
            $this->redirect('User/login');
        }
    }

    /* 验证码，用于登录和注册 */
    public function verify()
    {
        $verify = new \Think\Verify();
        $verify->entry(1);
    }

    /* 用户密码找回首页 */
    public function mi($username = '', $email = '', $verify = '')
    {
        $username = strval($username);
        $email = strval($email);

        if (IS_POST) { //登录验证
            //检测验证码

            if (!check_verify($verify)) {
                $this->error('验证码输入错误');
            }

            //根据用户名获取用户UID
            $user = D('User/UcenterMember')->where(array('username' => $username, 'email' => $email, 'status' => 1))->find();
            $uid = $user['id'];
            if (!$uid) {
                $this->error("用户名或邮箱错误");
            }

            //生成找回密码的验证码
            $verify = $this->getResetPasswordVerifyCode($uid);

            //发送验证邮箱
            $url = 'http://' . $_SERVER['HTTP_HOST'] . U('Home/User/reset?uid=' . $uid . '&verify=' . $verify);
            $content = C('USER_RESPASS') . "<br/>" . $url . "<br/>" . C('WEB_SITE') . "系统自动发送--请勿直接回复<br/>" . date('Y-m-d H:i:s', TIME()) . "</p>";
            send_mail($email, C('WEB_SITE') . "密码找回", $content);
            $this->success('密码找回邮件发送成功', U('User/login'));
        } else {
            if (is_login()) {
                redirect(U('Weibo/Index/index'));
            }

            $this->display();
        }
    }

    /**
     * 重置密码
     */
    public function reset($uid, $verify)
    {
        //检查参数
        $uid = intval($uid);
        $verify = strval($verify);
        if (!$uid || !$verify) {
            $this->error("参数错误");
        }

        //确认邮箱验证码正确
        $expectVerify = $this->getResetPasswordVerifyCode($uid);
        if ($expectVerify != $verify) {
            $this->error("参数错误");
        }

        //将邮箱验证码储存在SESSION
        session('reset_password_uid', $uid);
        session('reset_password_verify', $verify);

        //显示新密码页面
        $this->display();
    }

    public function doReset($password, $repassword)
    {
        //确认两次输入的密码正确
        if ($password != $repassword) {
            $this->error('两次输入的密码不一致');
        }

        //读取SESSION中的验证信息
        $uid = session('reset_password_uid');
        $verify = session('reset_password_verify');

        //确认验证信息正确
        $expectVerify = $this->getResetPasswordVerifyCode($uid);
        if ($expectVerify != $verify) {
            $this->error("验证信息无效");
        }

        //将新的密码写入数据库
        $data = array('id' => $uid, 'password' => $password);
        $model = D('User/UcenterMember');
        $data = $model->create($data);
        if (!$data) {
            $this->error('密码格式不正确');
        }
        $result = $model->where(array('id' => $uid))->save($data);
        if ($result === false) {
            $this->error('数据库写入错误');
        }

        //显示成功消息
        $this->success('密码重置成功', U('Home/User/login'));
    }

    private function getResetPasswordVerifyCode($uid)
    {
        $user = D('User/UcenterMember')->where(array('id' => $uid))->find();
        $clear = implode('|', array($user['uid'], $user['username'], $user['last_login_time'], $user['password']));
        $verify = thinkox_hash($clear, UC_AUTH_KEY);
        return $verify;
    }

    /**
     * 获取用户注册错误信息
     * @param  integer $code 错误编码
     * @return string        错误信息
     */
    public function showRegError($code = 0)
    {
        switch ($code) {
            case -1:
                $error = '用户名长度必须在4-16个字符以内！';
                break;
            case -2:
                $error = '用户名被禁止注册！';
                break;
            case -3:
                $error = '用户名被占用！';
                break;
            case -4:
                $error = '密码长度必须在6-30个字符之间！';
                break;
            case -5:
                $error = '邮箱格式不正确！';
                break;
            case -6:
                $error = '邮箱长度必须在4-32个字符之间！';
                break;
            case -7:
                $error = '邮箱被禁止注册！';
                break;
            case -8:
                $error = '邮箱被占用！';
                break;
            case -9:
                $error = '手机格式不正确！';
                break;
            case -10:
                $error = '手机被禁止注册！';
                break;
            case -11:
                $error = '手机号被占用！';
                break;
            case -20:
                $error = '用户名只能由数字、字母和"_"组成！';
                break;
            case -30:
                $error = '昵称被占用！';
                break;
            case -31:
                $error = '昵称被禁止注册！';
                break;
            case -32:
                $error = '昵称只能由数字、字母、汉字和"_"组成！';
                break;
            case -33:
                $error = '昵称不能少于两个字！';
                break;
            default:
                $error = '未知错误24';
        }
        return $error;
    }


    /**
     * 修改密码提交
     * @author huajie <banhuajie@163.com>
     */
    public function profile()
    {
        if (!is_login()) {
            $this->error('您还没有登陆', U('User/login'));
        }
        if (IS_POST) {
            //获取参数
            $uid = is_login();
            $password = I('post.old');
            $repassword = I('post.repassword');
            $data['password'] = I('post.password');
            empty($password) && $this->error('请输入原密码');
            empty($data['password']) && $this->error('请输入新密码');
            empty($repassword) && $this->error('请输入确认密码');

            if ($data['password'] !== $repassword) {
                $this->error('您输入的新密码与确认密码不一致');
            }

            $Api = new UserApi();
            $res = $Api->updateInfo($uid, $password, $data);
            if ($res['status']) {
                $this->success('修改密码成功！');
            } else {
                $this->error($res['info']);
            }
        } else {
            $this->display();
        }
    }

    public function doSendVerify($account, $verify, $type)
    {
        switch ($type) {
            case 'mobile':
                //TODO 手机短信验证
                return true;
                break;
            case 'email':
                //发送验证邮箱
                $url = 'http://' . $_SERVER['HTTP_HOST'] . U('ucenter/member/checkVerify?account=' . $account . '&verify=' . $verify . '&type=email&uid=' . is_login());
                $content = modC('EMAIL_VERIFY', '{$callback_url}', 'USERCONFIG');
                $content = str_replace('{$callback_url}', $url, $content);
                $res = send_mail($account, C('WEB_SITE') . '邮箱验证', $content);
                return $res;
                break;
        }

    }

}