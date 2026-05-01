<?php
/**
 * @Author: JH-Ahua
 * @CreateTime: 2025/6/4 下午10:05
 * @email: admin@bugpk.com
 * @blog: www.jiuhunwl.cn
 * @Api: api.bugpk.com
 * @tip: 汽水音乐直链解析
 */
header('Content-type: application/json');
function qsmusic($url)
{

    // 构造请求数据
    $header = array('User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1 Edg/122.0.0.0');
    // 尝试从 URL 中获取视频 ID
    $id = extractId($url);

    // 检查 ID 是否有效
    if (empty($id)) {
        // 假设跳转后的 URL 会在响应头中返回，这里获取跳转后的 URL
        $redirectUrl = getFinalUrl($url);
        if ($redirectUrl) {
            // 尝试从跳转后的 URL 中获取视频 ID
            $id = extractId($redirectUrl);
        }
    }
    // 检查 ID 是否有效
    if (empty($id)) {
        return array('code' => 400, 'msg' => '无法解析视频 ID', 'data' => null);
    }
    $headers = [
        'cookie: ',
        'User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36',
        'X-Requested-With: XMLHttpRequest',
    ];
    // 发送请求获取视频信息
    $response = curl('https://beta-luna.douyin.com/luna/h5/seo_track?track_id=' . $id . '&device_platform=web', $headers);
    $result = json_decode($response, true);
    // 初始化音乐信息数组
    $musicinfojson = $result['seo_track']['track'] ?? [];

    $musicinfo = [];

    if (!empty($result) && isset($result['track_player']['video_model'])) {
        $musicurllist = json_decode($result['track_player']['video_model'], true);
        $musicurljson = $musicurllist['video_list'][0];
        $musicurl = $musicurljson['main_url'] ?? $musicurljson['backup_url'];
        $video_meta = $musicurljson['video_meta'];
        if (empty($musicurl)) {
            $musicinfo = ['code' => 404, 'msg' => '获取失败', 'data' => null];
        } else {
            $musicinfo = ['code' => 200, 'msg' => 'success', 'data' => [
                'url' => $musicurl,
                'video_meta' => $video_meta,
                'lyric' => $result['lyric']['content'] ?? '暂无歌词',
                'albumname' => $musicinfojson['album']['name'] ?? '未知专辑',
                'artistsid' => $musicinfojson['artists'][0]['user_info']['id'] ?? 0,
                'artistsname' => $musicinfojson['artists'][0]['user_info']['nickname'] ?? '未知艺术家',
                'artistsmedium_avatar_url' => $musicinfojson['artists'][0]['user_info']['medium_avatar_url']['urls'] ?? []
            ]];
        }
    } elseif (!empty($result) && isset($result['track_player']['url_player_info'])) {
        $musicurllistcurl = curl($result['track_player']['url_player_info']);
        $musicurllistjson = (json_decode($musicurllistcurl, true))['Result']['Data']['PlayInfoList'][0];
        $musicurl = $musicurllistjson['MainPlayUrl'] ?? $musicurllistjson['BackupPlayUrl'];
        if (empty($musicurl)) {
            $musicinfo = ['code' => 404, 'msg' => '获取失败', 'data' => null];
        } else {
            $musicinfo = ['code' => 200, 'msg' => 'success', 'data' => [
                'url' => $musicurl,
                'Bitrate' => $musicurllistjson['Bitrate'] ?? '未知比特率',
                'FileHash' => $musicurllistjson['FileHash'],
                'Size' => formatFileSize($musicurllistjson['Size'] ?? 0),
                'Format' => $musicurllistjson['Format'],
                'Codec' => $musicurllistjson['Codec'],
                'UrlExpire' => $musicurllistjson['UrlExpire'],
                'lyric' => $result['lyric']['content'] ?? '暂无歌词',
                'albumname' => $musicinfojson['album']['name'] ?? '未知专辑',
                'artistsid' => $musicinfojson['artists'][0]['user_info']['id'] ?? 0,
                'artistsname' => $musicinfojson['artists'][0]['user_info']['nickname'] ?? '未知艺术家',
                'artistsmedium_avatar_url' => $musicinfojson['artists'][0]['user_info']['medium_avatar_url']['urls'] ?? []
            ]];
        }
    } else {
        $musicinfo = ['code' => 404, 'msg' => '获取失败', 'data' => null];
    }

    return $musicinfo;
}

//计算文件大小
function formatFileSize($bytes, $precision = 2)
{
    if ($bytes === 0) {
        return '0 Bytes';
    }

    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    // 计算文件大小的数值部分
    $bytes /= pow(1024, $pow);

    // 返回格式化后的字符串
    return round($bytes, $precision) . ' ' . $units[$pow];
}

//获取重定向后的url
function getFinalUrl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 允许重定向
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 不验证SSL证书
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "请求时发生错误: $error";
        return null;
    }

    return $finalUrl;
}

function extractId($url)
{
    // 解析URL中的查询参数
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str($query, $params);

    if (isset($params['track_id'])) {
        return $params['track_id'];
    }

    // 新增：处理 ugc_video_id 参数
    if (isset($params['ugc_video_id'])) {
        return $params['ugc_video_id'];
    }

    // 如果查询参数中没有，尝试从路径中匹配
    $path = parse_url($url, PHP_URL_PATH);
    if (preg_match('/\/(?:track|video)\/(\d+)/', $path, $matches)) {
        return $matches[1];
    }

    // 处理带编码参数的URL
    $decodedUrl = urldecode($url);
    if (preg_match('/[?&]track_id=(\d+)/', $decodedUrl, $matches)) {
        return $matches[1];
    }

    // 新增：处理带编码的 ugc_video_id 参数
    if (preg_match('/[?&]ugc_video_id=(\d+)/', $decodedUrl, $matches)) {
        return $matches[1];
    }

    return null;
}


function curl($url, $header = null, $data = null)
{
    $con = curl_init((string)$url);
    curl_setopt($con, CURLOPT_HEADER, false);
    curl_setopt($con, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($con, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($con, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($con, CURLOPT_AUTOREFERER, 1);
    if (isset($header)) {
        curl_setopt($con, CURLOPT_HTTPHEADER, $header);
    }
    if (isset($data)) {
        curl_setopt($con, CURLOPT_POST, true);
        curl_setopt($con, CURLOPT_POSTFIELDS, $data);
    }
    curl_setopt($con, CURLOPT_TIMEOUT, 5000);
    $result = curl_exec($con);
    if ($result === false) {
        // 处理 curl 错误
        $error = curl_error($con);
        curl_close($con);
        trigger_error("cURL error: $error", E_USER_WARNING);
        return false;
    }
    curl_close($con);
    return $result;
}

// 使用空合并运算符检查 url 参数
$url = $_GET['url'] ?? '';
if (empty($url)) {
    echo json_encode(['code' => 201, 'msg' => 'url为空', 'data' => null], 480);
} else {
    $response = qsmusic($url);
    echo json_encode($response, 480);
}
?>