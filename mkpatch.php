#!/usr/bin/env php
<?php

class MKPatch
{
    private $_baseRepositoryDirPath = '~/work/reps';
    private $_patchDirPath = '~/Downloads/tickets';
    private $_repositoryVersions = ['magento2ce', 'magento2ee'];
    private $_gitFiles = [];
    private $_gitFilePrefix = [
            'git add ',
            'git reset ',
            'git checkout ',
    ];

    public function __construct()
    {
        $objectVars = get_object_vars($this);
        array_walk($objectVars, [$this,'_convertPath']);
    }

    private function _convertPath($path, $key)
    {
        if (is_string($path) && strpos($path, '~') !== false) {
            $homeDir = posix_getpwuid(posix_getuid())['dir'];

            $this->$key = str_replace('~', $homeDir, $path);
        }
    }

    public function run()
    {
        $options = getopt('b:r:');
        if (!isset($options['b'])) {
            exit("Please clarify branch name for search\n");
        }

        $branch = $options['b'];
        $repositoryVendor = isset($options['r']) ? $options['r'] : 'origin';

        foreach ($this->_repositoryVersions as $repositoryVersion) {
            $patchDirPath = $this->_patchDirPath . '/' . $branch . '/';
            chdir("$this->_baseRepositoryDirPath/{$repositoryVersion}/");
            $this->printLog('Repository dir: ' . shell_exec('pwd'));

            $this->addRemoteUrl($repositoryVendor, $repositoryVersion);
            $this->gitPull($repositoryVendor);
            $commits = $this->gitGrepCommits($branch);

            if (empty($commits)) {
                continue;
            }

            $patchDirPath .= $repositoryVendor . '.' . $repositoryVersion . '/';

            $this->printLog('Create patches at ' . $patchDirPath);
            if (!file_exists($patchDirPath)) {
                mkdir($patchDirPath, 0777, true);
            } else {
                $this->printLog('Directory not empty. Clear it');

                $fileToDelete = glob("$patchDirPath*");
                foreach ($fileToDelete as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }

            $i = 1;
            $this->_gitFiles = [];
            foreach ($commits as $c) {
                $commitDiff = shell_exec("git show $c");
                $fileName = $branch . '_' . $i++ . '_' . substr($c, 1, 3) . '.patch';
                file_put_contents($patchDirPath . $fileName, $commitDiff);
                $this->rectify($commitDiff, $patchDirPath, $fileName);
            }

            $this->_gitFiles = array_unique($this->_gitFiles);
            $data = $this->addPrefixes($this->_gitFiles);
            $this->printLog($data);
            file_put_contents($patchDirPath . 'git.add', implode(PHP_EOL, $data));
        }
    }

    public function addRemoteUrl($repositoryVendor, $repositoryVersion)
    {
        // Git add remote URL
        if ($repositoryVendor) {
            $command = "git remote add $repositoryVendor https://github.com/magento-$repositoryVendor/$repositoryVersion.git";
            $this->printLog("$command");
            $gitResult = shell_exec($command);
            $this->printLog(shell_exec('git remote -v'));
        }
    }

    public function gitPull($repositoryName)
    {
        // Git pull
        $command = "git pull $repositoryName";
        $this->printLog("$command");
        $gitResult = shell_exec($command);
        $this->printLog($gitResult);
    }

    public function gitGrepCommits($branch)
    {
        // Grep commits for branch
        $gitLog = shell_exec("git --no-pager log --all --grep='$branch:' --pretty=format:\'%H\'");
        if (!$gitLog) {
            $this->printLog("No any commit(s) in repo for $branch");
            return [];
        }
        $commits = explode(PHP_EOL, $gitLog);
        krsort($commits);

        $this->printLog(count($commits) . ' commits have been found:');
        $this->printLog($commits);

        return $commits;
    }

    public function rectify($diff, $dirPath, $fileName)
    {
        $newFiles = [];
        $existingFiles = [];

        $rectifyDir = $dirPath . 'rectify/';
        if (!file_exists($rectifyDir)) {
            printLog('Create rectify dir: ' . $dirPath . 'rectify/');
            mkdir($rectifyDir, 0777, true);
        }

        $diffParts = preg_split('/\ndiff/', $diff);

        foreach ($diffParts as $dp) {
            preg_match('/\-\-git\s([^\s]*)\s([^\s]*)\n/', $dp, $fileNames);

            if (!isset($fileNames[1]) || !isset($fileNames[2])) {
                continue;
            }

            $a = $fileNames[1];
            $b = $fileNames[2];

            $diffSingle = [
                'a' => $a,
                'b' => $b,
                'diff' => (preg_match('/^diff/', $dp) ? '' : 'diff') . $dp
            ];

            $diffArray[] = $diffSingle;

//            if (!preg_match('/(dev\/tests|Test|composer\.lock|composer\.json)/', $a)) {
            if (!preg_match('/(dev\/tests|Test)/', $a)) {
                $diffRectified[] = $diffSingle;

                if (preg_match('/(\nnew\sfile\smode)/', $dp)) {
                    $newFiles[] = preg_replace('/^a\//', '', $a);
                } else {
                    $existingFiles[] = preg_replace('/^a\//', '', $a);
                }
            }
        }

        $diffRectifiedPatch = '';
        if (isset($diffRectified)) {
            foreach ($diffRectified as $d) {
                $diffRectifiedPatch .= $d['diff'] . PHP_EOL;
            }

            file_put_contents($rectifyDir . $fileName . '.rectified', $diffRectifiedPatch);
            file_put_contents($rectifyDir . 'all.rectified', $diffRectifiedPatch, FILE_APPEND);

        }

        $this->_gitFiles = array_merge($this->_gitFiles, array_unique(array_merge($newFiles, $existingFiles)));
    }


    public function printLog($data)
    {
        echo '~~~~~~~~~~~~~~~~~~~~' . PHP_EOL;
        if (is_array($data)) {
            foreach ($data as $item) {
                echo $item . PHP_EOL;
            }
        } else {
            echo $data . PHP_EOL;
        }
    }

    public function addPrefixes($data)
    {
        $dataWithPrefixes = [];
        foreach ($this->_gitFilePrefix as $gitFilePrefix) {
            foreach ($data as $item) {
                $dataWithPrefixes[] = $gitFilePrefix . $item;
            }
            $dataWithPrefixes[] = '';
        }

        return $dataWithPrefixes;
    }
}

$mkpatch = new MKPatch();
$mkpatch->run();
