<?php

use plugin\admin\app\common\Util;

/**
 * Here is your custom functions.
 */

function getFilesAndDirectoriesRecursively($directory, $search = null, $pid = 0)
{
    $filesAndDirs = [];

    $items = scandir($directory);
    foreach ($items as $item) {
        if ($item != '.' && $item != '..') {
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                // 如果是目录，则递归获取目录下的文件和目录
                $fileName = pathinfo($path, PATHINFO_BASENAME);
                $modificationTime = date("Y-m-d H:i:s", filemtime($path));

                // 将文件信息存储到关联数组中
                $fileInfo = [
                    'name' => $fileName,
                    'path' => $path,
                    'lines' => '',
                    'type' => 'directory',
                    'size' => '',
                    'modification_time' => $modificationTime,
                    'time' => strtotime($modificationTime),
                    'id' => $path,
                    'isParent' => true,
                    'pid' => $pid,
                    'children' => getFilesAndDirectoriesRecursively($path, $search, $path),
                ];
                $filesAndDirs[] = $fileInfo;
            } else {
                if (pathinfo($path, PATHINFO_EXTENSION) == 'log') {

                    // 如果是文件，则将文件路径添加到数组中
                    $fileName = pathinfo($path, PATHINFO_BASENAME);
                    $lineCount = count(file($path));
                    $fileSize = filesize($path);
                    $modificationTime = date("Y-m-d H:i:s", filemtime($path));

                    // 将文件信息存储到关联数组中
                    $fileInfo = [
                        'id' => $path,
                        'name' => $fileName,
                        'path' => $path,
                        'lines' => $lineCount,
                        'type' => 'file',
                        'isParent' => false,
                        'pid' => $pid,
                        'size' => Util::formatBytes($fileSize),
                        'modification_time' => $modificationTime,
                        'time' => strtotime($modificationTime),
                    ];

                    if ($search) {
                        if (strpos($fileName, $search) !== false) {
                            $filesAndDirs[] = $fileInfo;
                        }
                    } else {
                        $filesAndDirs[] = $fileInfo;
                    }


                }

            }
        }
    }

    return $filesAndDirs;
}