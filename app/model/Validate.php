<?php

namespace app\model;
//验证码类
class Validate
{
    private $charset = 'abcdefghkmnprstuvwxyzABCDEFGHKMNPRSTUVWXYZ23456789'; //随机因子
    private $code = ''; //验证码
    private $codelen = 4; //验证码长度
    private $width = 130; //宽度
    private $height = 50; //高度
    private $img; //图形资源句柄
    private $font; //指定的字体
    private $fontsize = 20; //指定字体大小
    private $fontcolor; //指定字体颜色 

    //构造方法初始化
    public function __construct()
    {
        $rootPath = function_exists('root_path')
            ? root_path()
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $this->font = rtrim($rootPath, '/\\')
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'static'
            . DIRECTORY_SEPARATOR . 'admin'
            . DIRECTORY_SEPARATOR . 'css'
            . DIRECTORY_SEPARATOR . 'fonts'
            . DIRECTORY_SEPARATOR . 'code.ttc';

        // 兼容非标准站点根目录的部署方式。
        if (!is_file($this->font) || !is_readable($this->font)) {
            $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
            if ($documentRoot !== '') {
                $fallback = $documentRoot . DIRECTORY_SEPARATOR . 'static'
                    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'css'
                    . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'code.ttc';
                if (is_file($fallback) && is_readable($fallback)) {
                    $this->font = $fallback;
                }
            }
        }
    }

    //生成随机码
    private function createCode()
    {
        $this->code = '';
        $_len = strlen($this->charset) - 1;
        for ($i = 0; $i < $this->codelen; $i++) {
            $this->code .= $this->charset[mt_rand(0, $_len)];
        }
    }

    //生成背景
    private function createBg()
    {
        $this->img = imagecreatetruecolor($this->width, $this->height);
        $color = imagecolorallocate($this->img, mt_rand(157, 255), mt_rand(157, 255), mt_rand(157, 255));
        imagefilledrectangle($this->img, 0, $this->height, $this->width, 0, $color);
    }

    //生成文字
    private function createFont()
    {
        $_x = $this->width / $this->codelen;
        $y = (int) round($this->height / 1.4);
        for ($i = 0; $i < $this->codelen; $i++) {
            $this->fontcolor = imagecolorallocate($this->img, mt_rand(0, 156), mt_rand(0, 156), mt_rand(0, 156));
            $x = (int) round($_x * $i + mt_rand(1, 5));
            imagettftext($this->img, $this->fontsize, mt_rand(-30, 30), $x, $y, $this->fontcolor, $this->font, $this->code[$i]);
        }
    }

    //生成线条、雪花
    private function createLine()
    {
        for ($i = 0; $i < 6; $i++) {
            $color = imagecolorallocate($this->img, mt_rand(0, 156), mt_rand(0, 156), mt_rand(0, 156));
            imageline($this->img, mt_rand(0, $this->width), mt_rand(0, $this->height), mt_rand(0, $this->width), mt_rand(0, $this->height), $color);
        }
        for ($i = 0; $i < 100; $i++) {
            $color = imagecolorallocate($this->img, mt_rand(200, 255), mt_rand(200, 255), mt_rand(200, 255));
            imagestring($this->img, mt_rand(1, 5), mt_rand(0, $this->width), mt_rand(0, $this->height), '*', $color);
        }
    }

    //对外生成
    public function getImg()
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            throw new \RuntimeException('GD 扩展或 TrueType 字体函数不可用');
        }
        if (!is_file($this->font) || !is_readable($this->font)) {
            throw new \RuntimeException('验证码字体文件不存在或不可读');
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $this->createBg();
            $this->createCode();
            $this->createLine();
            $this->createFont();
            if (!imagepng($this->img)) {
                throw new \RuntimeException('验证码图片生成失败');
            }
            $image_data = ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        } finally {
            if ($this->img !== null) {
                imagedestroy($this->img);
                $this->img = null;
            }
        }

        if (!is_string($image_data) || $image_data === '') {
            throw new \RuntimeException('验证码图片内容为空');
        }

        return "data:image/png;base64," . base64_encode($image_data);
    }

    //获取验证码
    public function getCode()
    {
        return strtolower($this->code);
    }
    /**
     * 验证图形验证码
     *
     * @return void
     */
    public function validateImgCode($token, $code)
    {
        if (!$token) {
            return jerr("TOKEN参数丢失");
        }
        if (!$code) {
            return jerr("请输入验证码");
        }
        $code = strtoupper($code);
        $token = $token;
        $_code = cache($token);
        if (!$_code) {
            return jerr("验证码已过期");
        }
        if ($code != $_code) {
            return jerr('验证码错误');
        }
        // 删除设置的缓存
        cache($token, null);
        return null;
    }
}
