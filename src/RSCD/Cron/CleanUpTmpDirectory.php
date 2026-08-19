<?php

namespace RSCD\Cron;

/**
 * CleanUpTmpDirectory
 *
 * Deletes files from the application's tmp directory that are older than one
 * hour (based on ctime). Skips the special entries '.', '..', and 'tmp'.
 */
class CleanUpTmpDirectory extends \RSCD\Model\Cron {

    /**
     * Execute the cron job.
     *
     * Opens the tmp directory, iterates its contents, and unlinks any file
     * whose ctime is at least 3600 seconds in the past.
     *
     * @param array $argv Command-line arguments.
     * @return void
     */
    public function execute($argv) {
        $tmp = $this->getTmp();
        if(($handle = opendir($tmp)) === false) {
            return;
        }
        while(($file = readdir($handle)) !== false) {
            if(in_array($file, ['.', '..', 'tmp'])) {
                continue;
            }
            if(time() - filectime($tmp . DIRECTORY_SEPARATOR . $file) >= 60 * 60) {
                @unlink($tmp . DIRECTORY_SEPARATOR . $file);
            }
        }
        closedir($handle);
    }

}
