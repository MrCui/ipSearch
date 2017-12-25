<?php

/*
 + 	纯真数据库IP查找 By Cui 2014/7/22;
 +	修正版 2014/7/24 :
 +	修改了上一版模糊匹配IP的方式,增加注释;
 +	增加结果转码.修改显示方式;
 */

class IpSearch
{
    # 数据库地址;
    public $dataFile;
    # 文件句柄;
    private $handle;
    # 索引起始位置;
    private $offsetStart;
    # 索引结束位置;
    private $offsetEnd;
    # 索引总数;
    private $total;
    # 显示输入的信息;
    public $showInput = false;
    # 输入的查询IP或索引;
    private $input;
    # 国家地区分隔符;
    public $separator;
    # 中文编码;
    public $charset = 'GBK';

    # 配置;
    private function config()
    {

        if (!file_exists($this->dataFile)) {
            echo 'dataFile Undefind';
            exit();
        }

        $handle = fopen($this->dataFile, 'rb');

        if (!$handle) {
            echo 'Open dataFile Fail';
            exit();
        }

        $this->handle = $handle;

        # 文件头
        $this->offsetStart = $this->readF(4, 'L');
        $this->offsetEnd   = $this->readF(4, 'L');
        $this->total       = ($this->offsetEnd - $this->offsetStart) / 7 + 1;
    }

    # 查找;
    public function search($IP, $index = 0)
    {

        $this->config();

        $offset = !$IP && $index > 0 ? $this->byIndex($index) : $offset = $this->byIp($IP);

        if (false === $offset) {
            echo 'Error';
            exit();
        }

        return $this->getAddress($offset);
    }

    # 按IP查找
    private function byIp($IP)
    {

        $this->input = 'IP:' . $IP;

        $IP = ip2long($IP);

        if (!$IP) {
            return false;
        }

        $IP < 0 && $IP += pow(2, 32);

        $offset = $this->dichotomy($IP);

        return $offset;
    }

    # 按索引查找
    private function byIndex($index)
    {

        if ($index < 1 || $index > $this->total) {
            return false;
        }

        $this->input = 'Index ' . $index;

        $nowOffset = ($this->offsetStart + ($index - 1) * 7) + 4;

        $this->seekF($nowOffset);

        $offset = $this->readF(3, 'L', true);

        return $offset;
    }

    # 查找位置
    private function getAddress($offset)
    {

        # 移动到记录区,此时忽略结束IP;
        $this->seekF($offset + 4);

        # 在记录区查找;
        $info = $this->find();

        # CZ88.NET代表此处没有了,就不保留它了.
        if (strpos($info, 'CZ88.NET')) {
            $info = explode($this->separator, $info);
            $info = reset($info);
        }

        $result = '';

        if ($this->showInput) {
            $result .= $this->input . $this->separator;
        }

        # 因为数据库是gb2312编码的,我脚本是utf8,所以此处做一个转码;
        $info   = iconv('gb2312', $this->charset, $info);
        $result .= $info;

        return $result;
    }

    # 查找;
    private function find()
    {

        $result = '';
        $flag   = $cityOffset = false;

        /*
         + 循环查找;
         + 以最复杂的模式为例.
         + 假设重定向模式1 国家为A,地区为a,模式2为B,b,结果字符串为C,c;
         + "+"号代表地区对应国家的文件指针位置
         +
         + 1:国家 A => B => C;
         +             +         结果 = C.c;
         +   地区     a/b => c;
         +
         + 2:国家 B => B => C;
         +		  +
         +   地区 b => a/b => c;
         +
         + 3:国家 A => C
         +			   +
         +   地区      a/b => c
         +
         */
        while ($mode = $this->readF(1)) {

            # A a;
            if ($mode == chr(0x01)) {
                $offset = $this->readF(3, 'L', true);
                $this->seekF($offset);
                continue;
            }

            # B b;
            if ($mode == chr(0x02)) {
                $offset = $this->readF(3, 'L', true);
                # 国家为A/B,记录下该位置,为查地区做准备;
                !$cityOffset && $cityOffset = $this->tellF();
                $this->seekF($offset);
                continue;
            }

            # 文档提到过,如果是0则未知;
            if ($mode == chr(0x00)) {
                $result = '未知地区';
                break;
            }

            $this->seekF(-1, SEEK_CUR);

            # C c;
            while (($char = $this->readF(1)) != chr(0)) {
                $result .= $char;
            }

            # 记录 国家直接为C;
            !$cityOffset && $cityOffset = $this->tellF();

            # 查地区
            if (!$flag) {
                $this->seekF($cityOffset);
                $result .= $this->separator;
                $flag   = true;
                continue;
            }

            break;
        }

        return $result;
    }

    /*
     + 采用二分查找法 递归最大为100层,不用递归;
     + 普通遍历查找 218.91.156.62 用时68秒; 二分法用时 0.006;
     + 但二分查找法仅限于预排序的数据;
     */
    private function dichotomy($IP)
    {

        $searchStart = $ipEnd = $ipStart = $offset = 0;
        $searchEnd   = $this->total;

        /*
        + 开始并没明白记录区的IP怎么用,所以上个版本,当存在一个IP时,我会拆成三种情况         + 如192.168.1.1 我是分成三次匹配 192.168.1.1/192.168.1.0/192.168.0.0.
        + 但这样时间会被浪费,而且依旧不够准确,在参考了一些资料后.
        + 终于明白了这个记录区的IP是做什么的了,修改如下
        + 以索引区IP为开始范围,以记录区IP为结束范围;
        + 不断压缩范围,当循环停止时,会获得相等或最相近范围的结果.
        */
        while ($IP < $ipStart || $IP > $ipEnd) {

            $middle = ($searchStart + $searchEnd) / 2;
            $middle = floor($middle);

            # IP开始范围;
            $offset = $this->offsetStart + $middle * 7;
            $this->seekF($offset);
            $ipStart = $this->readF(4, 'L');
            $ipStart < 0 && $ipStart += pow(2, 32);

            if ($IP < $ipStart) {
                $searchEnd = $middle;
                continue;
            }

            # IP结束范围;
            $offset = $this->readF(3, 'L', true);
            $this->seekF($offset);
            $ipEnd = $this->readF(4, 'L');
            $ipEnd < 0 && $ipEnd += pow(2, 32);

            if ($IP > $ipEnd) {
                $searchStart = $middle;
            }
        }

        return $offset;
    }

    # 读文件;
    private function readF($length, $unpack = '', $pad = '')
    {
        $read = fread($this->handle, $length);
        if (!$unpack) {
            return $read;
        }
        $pad && $read .= chr(0);
        $read = unpack($unpack, $read);
        $read = reset($read);

        return $read;
    }

    # 移动文件指针;
    private function seekF($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence);
    }

    # 指针位置
    private function tellF()
    {
        return ftell($this->handle);
    }

    # 关闭;
    public function __destruct()
    {
        fclose($this->handle);
    }

}

/**************  测试  ****************/

# 没有做过太多测试,所以不确定是否全对;
$IpSearch            = new IpSearch();
$IpSearch->dataFile  = './qqwry.dat';//IP库地址;
$IpSearch->separator = '@';//国家地区间隔符;
# $IpSearch->charset = 'utf-8';//文字编码;
$IpSearch->showInput = true;//显示IP范围;

# 按IP查找;
$ip  = '220.137.208.218';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '61.139.126.41';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '115.28.76.187';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '42.120.158.49';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '220.181.111.85';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '218.91.156.62';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '202.108.33.60';
$res = $IpSearch->search($ip);
var_dump($res);

$ip  = '117.79.157.225';
$res = $IpSearch->search($ip);
var_dump($res);

#按数据索引查找;
$IpSearch->showInput = false;//显示IP范围;
$res                 = $IpSearch->search('', 2);
var_dump($res);

$res = $IpSearch->search('', 1);
var_dump($res);

$res = $IpSearch->search('', 450492);
var_dump($res);





