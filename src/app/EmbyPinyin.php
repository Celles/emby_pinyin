<?php
namespace App;

use GuzzleHttp\Client;
use Overtrue\Pinyin\Pinyin;

class EmbyPinyin
{

    protected $pinyin;
    protected $historyContentPath;
    protected $historyContent = [];
    protected $selected;
    protected $selectedByInput = false;
    protected $user;
    protected $items;
    protected $processCount = 0;

    public function __construct()
    {
        $this->historyContentPath = getcwd() . '/var/storage/history.data';
        $historyContentDir = dirname($this->historyContentPath);
        if(!file_exists($historyContentDir)) mkdir($historyContentDir, 0777, true);
        $this->pinyin = new Pinyin();
    }

    public function run()
    {
        echo "                 __                        __               __        
.-----.--------.|  |--.--.--.      .-----.|__|.-----.--.--.|__|.-----.
|  -__|        ||  _  |  |  |      |  _  ||  ||     |  |  ||  ||     |
|_____|__|__|__||_____|___  |______|   __||__||__|__|___  ||__||__|__|
by: hisune.com        |_____|______|__|             |_____| 
----------------------------------------------------------------------\r\n";
        $this->selectServer();
        $this->saveHistory();
        logger('开始获取用户信息');
        $this->initUser();
        logger('开始获取媒体库信息');
        $this->initItems();
        $this->toPinyin();
    }

    protected function selectServer()
    {
        if(!file_exists($this->historyContentPath)) {
            logger("未找到history.data文件", false);
            $this->selectByInput();
            return;
        }

        logger('加载historyContent: ' . $this->historyContentPath, false);
        $historyContent = file_get_contents($this->historyContentPath);
        logger($historyContent, false);
        $historyContent = json_decode($historyContent, true);
        if(!$historyContent){
            logger("history.data文件格式不正确");
            copy($this->historyContentPath, $this->historyContentPath . '.' . time()); // 删除文件
            unlink($this->historyContentPath);
            $this->selectServer();
            return;
        }
        $this->historyContent = $historyContent;
        $count = count($this->historyContent);
        foreach($this->historyContent as $key => $data){
            echo ($key + 1) . ") 地址：{$data['host']}\tAPI密钥：{$data['key']}\r\n";
        }
        echo "0) 使用新的服务器地址和API密钥\r\n";
        $ask = ask("找到 $count 个历史emby服务器，输入编号直接选取，或编号前加减号 - 删除该历史记录，例如：-1");
        if($ask == '0'){
            $this->selectByInput();
        }else{
            $this->selectByHistory($ask);
        }
    }

    private function selectByInput()
    {
        $this->selectHostByInput();
        $this->selectKeyByInput();
    }

    private function selectHostByInput()
    {
        $ask = ask('请输入你的emby服务器地址，例如：http://192.168.1.1:8096，也可省略http://或端口（默认为http和8096）');
        if(!$ask) $this->selectHostByInput();
        $parseUrl = parse_url($ask);
        if(!isset($parseUrl['scheme'])) $parseUrl['scheme'] = 'http';
        if(!isset($parseUrl['port'])) $parseUrl['port'] = '8096';
        $this->selected['host'] = $parseUrl['scheme'] . '://' . ($parseUrl['host'] ?? $parseUrl['path']) . ':' . $parseUrl['port'];
    }

    private function selectKeyByInput()
    {
        $ask = ask('请输入你的API密钥，密钥需要使用【管理员账号】在emby管理后台的[高级]->[API密钥]进行创建和获取：');
        if(!$ask) $this->selectKeyByInput();
        $this->selected['key'] = $ask;
        $this->selectedByInput = true;
    }

    private function selectByHistory($answer)
    {
        $num = abs($answer);
        if(!isset($this->historyContent[$num - 1])){
            logger("\r\n编号：{$num} 无效，请重新选取");
            $this->selectServer();
            return false;
        }
        if($answer < 0){ // 删除
            unset($this->historyContent[$num - 1]);
            $this->historyContent = array_values($this->historyContent); // 重新排序
            if(!$this->historyContent){
                unlink($this->historyContentPath);
            }else{
                $this->writeHistory();
            }
            logger("已删除编号：{$num} 的数据");
            $this->selectServer();
        }else{ // 选取
            $this->selected = $this->historyContent[$num - 1];
        }
        return true;
    }

    private function writeHistory()
    {
        return file_put_contents($this->historyContentPath, json_encode($this->historyContent));
    }

    protected function saveHistory()
    {
        if($this->selectedByInput){
            $this->historyContent[] = $this->selected;
            $this->writeHistory();
        }
    }

    protected function initUser()
    {
        $users = $this->sendRequest('Users');
        logger(json_encode($users), false);
        foreach($users as $user){
            if($user['Policy']['IsAdministrator']){
                $this->user = $user;
            }
        }
        if(!$this->user){
            failure('未找到管理员账户，请检查你的API KEY参数');
        }
    }

    protected function initItems()
    {
        $items = $this->sendRequest('Items');
        logger(json_encode($items), false);
        logger("获取到 {$items['TotalRecordCount']} 个媒体库");
        $this->items = $items;
    }

    protected function toPinyin()
    {
        foreach($this->items['Items'] as $item){
            if(!$item['IsFolder']) {
                logger('跳过非目录：' . $item['Name'], false);
                continue;
            }
            if($item['Name'] == 'playlists'){
                logger('自动跳过playlists：' . $item['Id']);
                continue;
            }

            $ask = ask("是否处理此媒体库 【{$item['Name']}】 下的所有媒体？(y/n)");
            if($ask == 'y'){
                logger("开始处理 【{$item['Name']}】");
                $this->renderFolder($item['Id']);
            }else{
                logger("跳过 【{$item['Name']}】", false);
            }
        }
    }

    private function renderFolder($id)
    {
        $items = $this->sendRequest('Items', ['ParentId' => $id]);
        logger(json_encode($items), false);
        foreach($items['Items'] as $item){
            if($item['Type'] == 'Folder'){
                $this->renderFolder($item['Id']);
            }else if($item['Type'] == 'Series' || $item['Type'] == 'Movie'){
                // 获取item详情
                $itemDetail = $this->sendRequest("Users/{$this->user['Id']}/Items/{$item['Id']}");
                $pinyinAbbr = $this->pinyin->abbr($itemDetail['Name']);
                $itemDetail['SortName'] = $pinyinAbbr;
                $itemDetail['ForcedSortName'] = $pinyinAbbr;
                $itemDetail['LockedFields'] = ['SortName'];
                // 修改
                $this->sendRequest("/Items/{$item['Id']}", [], $itemDetail);
                $this->processCount++;
                echo "已处理：{$this->processCount}\r";
            }
        }
    }

    private function sendRequest($uri, $params = [], $postData = [])
    {
        try{
            if($params){
                $paramsString = '&' . http_build_query($params);
            }else{
                $paramsString = '';
            }
            $client = new Client();
            if(!$postData){
                $response = $client->get("{$this->selected['host']}/{$uri}?api_key={$this->selected['key']}{$paramsString}");
            }else{
                $response = $client->post("{$this->selected['host']}/{$uri}?api_key={$this->selected['key']}{$paramsString}", [
                    'json' => $postData,
                ]);
            }
            $content = $response->getBody()->getContents();
            if($response->getStatusCode() != 200 && $response->getStatusCode() != 204){
                failure('响应错误，检查您的参数：' . $response->getStatusCode() . ' with ' . $content);
            }else{
                logger($content, false);
                return json_decode($content, true);
            }
        }catch (\Exception $e){
            failure('响应错误，检查您的服务器地址配置：' . $e->getMessage());
        }
    }
}