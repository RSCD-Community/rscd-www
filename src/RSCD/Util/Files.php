<?php

namespace RSCD\Util;

use RSCD\Util\Arrays;

/**
 * Filesystem utility helpers.
 *
 * Provides safe wrappers around PHP's file and directory functions: lock files,
 * directory listing and inclusion, file deletion, filename sanitization, and
 * HTML-to-PDF conversion. All methods are static.
 */
class Files {

    /**
     * Check whether a file is currently locked (i.e. a .lock sidecar file exists).
     *
     * @param  string  $file  Absolute path to the file to check.
     * @return bool           True if both the file and its .lock sidecar exist.
     */
    public static function locked($file) {
        return file_exists($file) && file_exists($file . '.lock');
    }

    /**
     * Create a .lock sidecar file for the given path, recording a timestamp and
     * any caller-supplied metadata as JSON.
     *
     * Does nothing (returns false) if the file is already locked, or if
     * $existenceRequired is true and the target file does not exist.
     *
     * @param  string  $file               Absolute path to the file to lock.
     * @param  array   $additionalInfo     Optional key/value metadata to embed in the lock file.
     * @param  bool    $existenceRequired  If true, the target file must exist before locking.
     * @return int|false                   Bytes written on success, false if lock was skipped.
     */
    public static function lock($file, $additionalInfo = [], $existenceRequired = true) {
        if(!self::locked($file) && (!$existenceRequired || file_exists($file))) {
            $object = new \stdClass();
            $object->timeLocked = time();

            if(!empty($additionalInfo) && is_array($additionalInfo)) {
                foreach($additionalInfo as $key => $value) {
                    $object->$key = $value;
                }
            }

            return file_put_contents($file . '.lock', json_encode($object));
        }

        return false;
    }

    /**
     * Remove the .lock sidecar file for the given path.
     *
     * Does nothing (returns false) if no lock file exists.
     *
     * @param  string  $file  Absolute path to the locked file.
     * @return bool           True on successful removal, false if not locked.
     */
    public static function unlock($file) {
        if(self::locked($file)) {
            return unlink($file . '.lock');
        }

        return false;
    }

    /**
     * List all files (and optionally subdirectories) within a directory.
     *
     * Returns an array of entries, each with keys: 'name', 'path', and 'type'
     * ('file' or 'directory'). When $recursive is true, subdirectory contents
     * are appended after the directory entry itself.
     *
     * @param  string  $directory  Absolute path to the directory to list.
     * @param  bool    $recursive  Whether to recurse into subdirectories.
     * @return array               List of ['name', 'path', 'type'] entries.
     */
    public static function listDir($directory, $recursive = false) {
        $files = [];

        if($directory !== null && is_dir($directory)) {
            if(($handler = opendir($directory)) !== false) {
                while(($fileName = readdir($handler)) !== false) {
                    if($fileName == '.' || $fileName == '..') {
                        continue;
                    }

                    $isDir = is_dir($directory . DIRECTORY_SEPARATOR . $fileName);

                    $files[] = [
                        'name' => $fileName,
                        'path' => $directory . DIRECTORY_SEPARATOR . $fileName,
                        'type' => ($isDir ? 'directory' : 'file'),
                    ];

                    if($isDir && $recursive) {
                        $files = array_merge($files, self::listDir($directory . DIRECTORY_SEPARATOR . $fileName, $recursive));
                    }
                }

                closedir($handler);
            }
        }

        return $files;
    }

    /**
     * Include all files with a given extension in a directory.
     *
     * Iterates over the directory and calls include() on each file whose name
     * contains $fileExtension. If $recursive is true, subdirectories are also
     * traversed. Silently does nothing if the directory does not exist.
     *
     * @param  string  $directory      Absolute path to the directory.
     * @param  bool    $recursive      Whether to recurse into subdirectories.
     * @param  string  $fileExtension  File extension to match (default '.php').
     * @return void
     */
    public static function includeDir($directory, $recursive = false, $fileExtension = '.php') {
        if($directory !== null && is_dir($directory)) {
            if(($handler = opendir($directory)) !== false) {
                while(($fileName = readdir($handler)) !== false) {
                    if($fileName == '.' || $fileName == '..') {
                        continue;
                    }

                    if(strpos($fileName, $fileExtension) !== false) {
                        include($directory . DIRECTORY_SEPARATOR . $fileName);
                    } else if(is_dir($directory . DIRECTORY_SEPARATOR . $fileName) && $recursive) {
                        self::includeDir($directory . DIRECTORY_SEPARATOR . $fileName, $recursive);
                    }
                }

                closedir($handler);
            }
        }
    }

    /**
     * Require all files with a given extension in a directory.
     *
     * Behaves like includeDir() but uses require() instead of include(), so a
     * missing file triggers a fatal error rather than a warning.
     *
     * @param  string  $directory      Absolute path to the directory.
     * @param  bool    $recursive      Whether to recurse into subdirectories.
     * @param  string  $fileExtension  File extension to match (default '.php').
     * @return void
     */
    public static function requireDir($directory, $recursive = false, $fileExtension = '.php') {
        if($directory !== null && is_dir($directory)) {
            if(($handler = opendir($directory)) !== false) {
                while(($fileName = readdir($handler)) !== false) {
                    if($fileName == '.' || $fileName == '..') {
                        continue;
                    }

                    if(strpos($fileName, $fileExtension) !== false) {
                        require($directory . DIRECTORY_SEPARATOR . $fileName);
                    } else if(is_dir($directory . DIRECTORY_SEPARATOR . $fileName) && $recursive) {
                        self::requireDir($directory . DIRECTORY_SEPARATOR . $fileName, $recursive);
                    }
                }

                closedir($handler);
            }
        }
    }

    /**
     * Require-once all files with a given extension in a directory.
     *
     * Behaves like requireDir() but uses require_once(), so each file is loaded
     * at most once per request regardless of how many times this method is called.
     *
     * @param  string  $directory      Absolute path to the directory.
     * @param  bool    $recursive      Whether to recurse into subdirectories.
     * @param  string  $fileExtension  File extension to match (default '.php').
     * @return void
     */
    public static function requireOnceDir($directory, $recursive = false, $fileExtension = '.php') {
        if($directory !== null && is_dir($directory)) {
            if(($handler = opendir($directory)) !== false) {
                while(($fileName = readdir($handler)) !== false) {
                    if($fileName == '.' || $fileName == '..') {
                        continue;
                    }

                    if(strpos($fileName, $fileExtension) !== false) {
                        require_once($directory . DIRECTORY_SEPARATOR . $fileName);
                    } else if(is_dir($directory . DIRECTORY_SEPARATOR . $fileName) && $recursive) {
                        self::requireOnceDir($directory . DIRECTORY_SEPARATOR . $fileName, $recursive);
                    }
                }

                closedir($handler);
            }
        }
    }

    /**
     * Delete one or more files, silently skipping any that do not exist.
     *
     * Accepts a single file path or an array of paths (via Arrays::fromMixed()).
     * Each file is skipped if it does not exist; otherwise unlink() is called.
     *
     * @param  string|array  $files  File path or array of file paths to delete.
     * @param  bool          $quiet  If false, exceptions from unlink() are re-thrown.
     * @return void
     */
    public static function delete($files, $quiet = true) {
        $array = Arrays::fromMixed($files);
        foreach($array as $file) {
            if(!file_exists($file)) {
                continue;
            }
            self::unlink($file, $quiet);
        }
    }

    /**
     * Convert a php.ini shorthand size value (e.g. "2M", "512M", "1G") to bytes.
     *
     * Supports the K/M/G suffixes php.ini uses for directives like
     * upload_max_filesize and post_max_size. Unsuffixed values are treated as
     * raw bytes. Used to compare the server's real upload ceiling against
     * application-level limits when reporting upload errors.
     *
     * @param  string  $value  Shorthand size string from ini_get().
     * @return int             Size in bytes.
     */
    public static function iniToBytes($value) {
        $value = trim((string)$value);
        if($value === '') {
            return 0;
        }
        $suffix = strtolower(substr($value, -1));
        $number = (int)$value;
        switch($suffix) {
            case 'g':
                return $number * 1073741824;
            case 'm':
                return $number * 1048576;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * Unlink (delete) a single file with optional exception suppression.
     *
     * Uses @unlink to suppress PHP warnings. Returns false if the file does not
     * exist or deletion fails. In non-quiet mode throws an Exception on failure.
     *
     * @param  string  $file   Absolute path to the file to delete.
     * @param  bool    $quiet  If true, suppress exceptions and return false on error.
     * @return bool            True on success, false on failure (quiet mode only).
     * @throws \Exception      Re-thrown when $quiet is false and unlink() fails.
     */
    public static function unlink($file, $quiet = true) {
        if(!file_exists($file)) {
            return false;
        }
        $result = @unlink($file);
        if(!$result && $quiet !== true) {
            throw new \Exception('Failed to delete file: ' . $file);
        }
        return $result;
    }

    /**
     * Sanitize a user-supplied filename to remove unsafe characters.
     *
     * Applies two passes of multibyte regex replacement:
     *  1. Strip any character that is not a word character (\w), whitespace (\s),
     *     digit (\d), hyphen, underscore, tilde, comma, semicolon, square brackets,
     *     parentheses, or period.
     *  2. Collapse sequences of two or more consecutive dots to prevent directory
     *     traversal via ".." path segments.
     *
     * @param  string  $name  Raw user-supplied filename.
     * @return string         Sanitized filename safe for use in file paths.
     */
    public static function getSafeName($name) {
        return mb_ereg_replace('([\.]{2,})', '', mb_ereg_replace('([^\w\s\d\-_~,;\[\]\(\).])', '', $name));
    }

    /**
     * Delete a single file or directory from the local filesystem.
     *
     * Directories are removed with rmdir(); files are removed with unlink().
     * Paths that do not exist are silently ignored.
     *
     * @param  string  $path  Absolute path to the file or directory to delete.
     * @return void
     */
    public static function cleanUpPath($path) {
        if(is_dir($path)) {
            rmdir($path);
        } else if(file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Delete a list of local filesystem paths in reverse order.
     *
     * Reversing the list ensures that files inside directories are deleted before
     * the directories themselves, preventing rmdir() failures on non-empty dirs.
     * Accepts a single path string or an array of paths (via Arrays::fromMixed()).
     *
     * @param  string|string[]  $paths  A single path or array of paths to delete.
     * @return void
     */
    public static function cleanUp($paths) {
        $reversed = array_reverse(Arrays::fromMixed($paths));
        foreach($reversed as $path) {
            self::cleanUpPath($path);
        }
    }

    /**
     * Convert an HTML string to a PDF file on disk using mPDF.
     *
     * Iterates over pages of HTML and writes each as a separate mPDF page,
     * inserting a page break between pages. The resulting PDF is written to
     * the provided file path via mPDF's FILE destination.
     *
     * @param  array   $pagesHtml        Array of HTML strings, one per PDF page.
     * @param  string  $pdfFileFullPath  Absolute path where the PDF will be written.
     * @param  int     $docDpi           Document DPI passed to mPDF (default 203).
     * @param  int     $docImageDpi      Image DPI within the document (default 72).
     * @return void
     */
    public static function convertHtmlToPdf($pagesHtml, $pdfFileFullPath, $docDpi = 203, $docImageDpi = 72) {
        $mpdf = new \Mpdf\Mpdf(['mode' => 'c', 'dpi' => $docDpi, 'img_dpi' => $docImageDpi]);
        foreach($pagesHtml as $i => $pageHtml) {
            $mpdf->WriteHTML(($i > 0 ? '<pagebreak/>' : '') . $pageHtml);
        }
        $mpdf->Output($pdfFileFullPath, \Mpdf\Output\Destination::FILE);
    }

    /**
     * Find all PNG files in a directory that share the same base pattern as
     * the given file path.
     *
     * The match pattern is derived from the last hyphen-delimited segment of
     * the filename (e.g. for "label-page-001.png" the pattern is "001.png").
     * Any file in the same directory whose name contains that pattern is included.
     *
     * Used to collect multi-page PNG output files produced by Ghostscript, which
     * appends page numbers to the base filename.
     *
     * @param  string  $pngFileFullPath  Absolute path to one of the PNG files (used to derive the pattern).
     * @return string[]                  Array of absolute paths to all matching PNG files.
     */
    public static function listPngs($pngFileFullPath) {
        $pngFileFullPaths = [];
        $fileName = basename($pngFileFullPath);
        $filePath = str_replace($fileName, '', $pngFileFullPath);
        $fileNameArray = explode('-', $fileName);
        $matchPattern = end($fileNameArray);
        $handle = opendir($filePath);

        if($handle === false) {
            return [];
        }

        while(($currentFileName = readdir($handle)) !== false) {
            if(strpos($currentFileName, $matchPattern) !== false) {
                $pngFileFullPaths[] = $filePath . $currentFileName;
            }
        }

        closedir($handle);
        return $pngFileFullPaths;
    }

}
