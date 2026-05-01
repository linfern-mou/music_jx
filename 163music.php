<?php
/**
*@Author: JH-Ahua
*@CreateTime: 2026/5/1 13:49
*@email: admin@bugpk.com
*@blog: www.jiuhunwl.cn
*@Api: api.bugpk.com
*@tip: 网易云音乐解析
*/

require_once 'getMusicapi.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

function returnErroroutput($code, $msg) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

function returntrueoutput($data, $msg = '成功') {
    return ['code' => 200, 'msg' => $msg, 'data' => $data];
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . 'GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . 'MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . 'KB';
    } else {
        return $bytes . 'B';
    }
}

function formatLevel($level) {
    $levelMap = [
        'standard' => '标准音质',
        'exhigh' => '极高音质',
        'lossless' => '无损音质',
        'hires' => 'Hires音质',
        'jyeffect' => '高清环绕声',
        'sky' => '沉浸环绕声',
        'jymaster' => '超清母带'
    ];
    return $levelMap[$level] ?? $level;
}

function getLevelPriority() {
    return ['jymaster', 'hires', 'lossless', 'exhigh', 'standard', 'sky', 'jyeffect'];
}

function isValidLevel($level) {
    $levelMap = [
        'standard' => '标准音质',
        'exhigh' => '极高音质',
        'lossless' => '无损音质',
        'hires' => 'Hires音质',
        'jyeffect' => '高清环绕声',
        'sky' => '沉浸环绕声',
        'jymaster' => '超清母带'
    ];
    return isset($levelMap[$level]);
}

function extractIdFromUrl($url) {
    $patterns = [
        '/[?&]id=(\d+)/',
        '/song\?id=(\d+)/',
        '/song\/(\d+)/',
        '#/song\?id=(\d+)#',
        '#/song/(\d+)#',
        '/(\d{5,})/'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

try {
    $api = new NeteaseMusicAPI();
    $cookies = [];
    // 你的Cookie字符串
    $cookieString = 'MUSIC_U=;os=pc;appver=8.9.75;';
    try {
        // 直接调用新增的方法，传入字符串
        $cookies = $api->loadCookieFromString($cookieString);
    } catch (Exception $e) {
        echo "解析Cookie失败: " . $e->getMessage();
    }

    $type = $_REQUEST['type'] ?? '';
    if (empty($type)) {
        returnErroroutput(400, '缺少type参数，支持的类型: search, song, url, lyric, playlist, album, music, json, text, down');
    }

    $result = [];

    switch ($type) {
        case 'search':
            $keywords = $_REQUEST['id'] ?? $_REQUEST['s'] ?? $_REQUEST['keywords'] ?? '';
            if (empty($keywords)) returnErroroutput(400, '缺少搜索关键词 (参数: id/s/keywords)');

            $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 20;
            $offset = isset($_REQUEST['offset']) ? (int)$_REQUEST['offset'] : 0;

            $rawResult = $api->getSearchMusic($keywords, $limit, $offset, $cookies);
            $result = returntrueoutput($rawResult, '搜索成功');
            break;

        case 'song':
            $id = $_REQUEST['id'] ?? '';
            if (empty($id)) returnErroroutput(400, '缺少id参数');

            $rawResult = $api->getSongDetail($id, $cookies);

            if (isset($rawResult['songs'][0])) {
                $song = $rawResult['songs'][0];
                $artists = [];
                if (isset($song['ar'])) {
                    foreach ($song['ar'] as $artist) {
                        $artists[] = $artist['name'];
                    }
                }

                $songData = [
                    'id' => $song['id'],
                    'name' => $song['name'],
                    'album' => $song['al']['name'] ?? '',
                    'singer' => implode('/', $artists),
                    'picimg' => $song['al']['picUrl'] ?? ''
                ];
                $result = returntrueoutput($songData, '获取歌曲成功');
            } else {
                $result = ['code' => 404, 'msg' => '未找到歌曲', 'data' => null];
            }
            break;

        case 'url':
            $id = $_REQUEST['id'] ?? '';
            if (empty($id)) returnErroroutput(400, '缺少id参数');

            $ids = strpos($id, ',') !== false ? explode(',', $id) : $id;
            $level = $_REQUEST['level'] ?? $_REQUEST['quality'] ?? 'standard';

            if (!isValidLevel($level)) {
                $level = 'standard';
            }

            $levelPriority = getLevelPriority();
            $currentLevelIndex = array_search($level, $levelPriority);
            if ($currentLevelIndex === false) {
                $currentLevelIndex = array_search('standard', $levelPriority);
            }

            $list = [];
            $found = false;

            while ($currentLevelIndex < count($levelPriority) && !$found) {
                $tryLevel = $levelPriority[$currentLevelIndex];
                $rawResult = $api->getMusicUrl($ids, $tryLevel, $cookies);

                if (isset($rawResult['data']) && !empty($rawResult['data'])) {
                    foreach ($rawResult['data'] as $urls) {
                        if (!empty($urls['url'])) {
                            $list[] = [
                                'id' => $urls['id'],
                                'url' => str_replace("http://", "https://", $urls['url']),
                                'br' => $urls['br'],
                                'level' => $urls['level'],
                                'size' => $urls['size'],
                                'md5' => $urls['md5']
                            ];
                        }
                    }
                    if (!empty($list)) {
                        $found = true;
                        $result = returntrueoutput($list, '获取播放链接成功');
                    }
                }

                $currentLevelIndex++;
            }

            if (!$found) {
                $result = ['code' => 500, 'msg' => '获取播放链接失败', 'data' => null];
            }
            break;

        case 'lyric':
            $id = $_REQUEST['id'] ?? '';
            if (empty($id)) returnErroroutput(400, '缺少id参数');

            $rawResult = $api->getLyric($id, $cookies);

            $lyricData = [
                'lrc' => $rawResult['lrc']['lyric'] ?? '',
                'tlyric' => $rawResult['tlyric']['lyric'] ?? '',
                'romalrc' => $rawResult['romalrc']['lyric'] ?? '',
                'klyric' => $rawResult['klyric']['lyric'] ?? ''
            ];
            $result = returntrueoutput($lyricData, '获取歌词成功');
            break;

        case 'playlist':
            $id = $_REQUEST['id'] ?? '';
            if (empty($id)) returnErroroutput(400, '缺少id参数');

            $rawResult = $api->getPlaylistDetail($id, $cookies);
            if ($rawResult) {
                $result = returntrueoutput($rawResult, '获取歌单成功');
            } else {
                $result = ['code' => 404, 'msg' => '未找到歌单', 'data' => null];
            }
            break;

        case 'album':
            $id = $_REQUEST['id'] ?? '';
            if (empty($id)) returnErroroutput(400, '缺少id参数');

            $rawResult = $api->getAlbumDetail($id, $cookies);
            if ($rawResult) {
                $result = returntrueoutput($rawResult, '获取专辑成功');
            } else {
                $result = ['code' => 404, 'msg' => '未找到专辑', 'data' => null];
            }
            break;

        case 'music':
        case 'json':
        case 'text':
        case 'down':
            $url = $_REQUEST['url'] ?? '';
            $ids = $_REQUEST['ids'] ?? '';
            $level = $_REQUEST['level'] ?? 'standard';

            if (empty($url) && empty($ids)) {
                returnErroroutput(400, '缺少url或ids参数 (二选一)');
            }

            $songId = '';
            if (!empty($url)) {
                $songId = extractIdFromUrl($url);
                if (!$songId) {
                    returnErroroutput(400, '无法从链接中提取歌曲ID');
                }
            } else {
                $songId = $ids;
            }

            $songDetail = $api->getSongDetail($songId, $cookies);
            if (!isset($songDetail['songs'][0])) {
                returnErroroutput(404, '未找到歌曲');
            }

            $song = $songDetail['songs'][0];

            $artists = [];
            if (isset($song['ar'])) {
                foreach ($song['ar'] as $artist) {
                    $artists[] = $artist['name'];
                }
            }

            $lyricResult = $api->getLyric($songId, $cookies);

            if (!isValidLevel($level)) {
                $level = 'standard';
            }

            $levelPriority = getLevelPriority();
            $currentLevelIndex = array_search($level, $levelPriority);
            if ($currentLevelIndex === false) {
                $currentLevelIndex = array_search('standard', $levelPriority);
            }

            $musicUrl = '';
            $size = 0;
            $actualLevel = $level;

            while ($currentLevelIndex < count($levelPriority)) {
                $tryLevel = $levelPriority[$currentLevelIndex];
                $urlResult = $api->getMusicUrl($songId, $tryLevel, $cookies);

                if (isset($urlResult['data']) && isset($urlResult['data'][0]) && !empty($urlResult['data'][0]['url'])) {
                    $urlData = $urlResult['data'][0];
                    $musicUrl = str_replace("http://", "https://", $urlData['url']);
                    $size = $urlData['size'] ?? 0;
                    $actualLevel = $urlData['level'] ?? $tryLevel;
                    break;
                }

                $currentLevelIndex++;
            }

            $musicData = [
                'name' => $song['name'] ?? '',
                'ar_name' => implode('/', $artists),
                'al_name' => $song['al']['name'] ?? '',
                'pic' => $song['al']['picUrl'] ?? '',
                'url' => $musicUrl,
                'size' => formatSize($size),
                'level' => formatLevel($actualLevel),
                'lyric' => $lyricResult['lrc']['lyric'] ?? '',
                'tlyric' => $lyricResult['tlyric']['lyric'] ?? ''
            ];

            if ($type === 'text') {
                header('Content-Type: text/plain; charset=utf-8');
                echo "歌名: " . $musicData['name'] . "\n";
                echo "歌手: " . $musicData['ar_name'] . "\n";
                echo "专辑: " . $musicData['al_name'] . "\n";
                echo "音质: " . $musicData['level'] . "\n";
                echo "大小: " . $musicData['size'] . "\n";
                echo "封面: " . $musicData['pic'] . "\n";
                echo "链接: " . $musicData['url'] . "\n";
                echo "\n===== 歌词 =====\n";
                echo $musicData['lyric'] ?: '暂无歌词';
                echo "\n===== 翻译 =====\n";
                echo $musicData['tlyric'] ?: '暂无翻译';
                return;
            }

            if ($type === 'down' && !empty($musicUrl)) {
                $filename = $musicData['name'] . ' - ' . $musicData['ar_name'];
                $ext = (strpos($level, 'lossless') !== false || strpos($level, 'hires') !== false) ? 'flac' : 'mp3';

                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '.' . $ext . '"');
                header('Location: ' . $musicUrl);
                exit;
            }

            $result = array_merge(['status' => 200], $musicData);
            break;

        default:
            returnErroroutput(400, '无效的type类型');
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    returnErroroutput(500, $e->getMessage());
}
?>
