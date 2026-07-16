<?php

function dialecticLogFfmpegError($message)
{
    if (class_exists('Logger')) {
        Logger::error($message);
    }
}

function dialecticResolveFfmpegPath()
{
    $candidates = [];

    if (!empty($GLOBALS["FFMPEG_PATH"])) {
        $candidates[] = $GLOBALS["FFMPEG_PATH"];
    }

    $envPath = getenv('FFMPEG_PATH');
    if (!empty($envPath)) {
        $candidates[] = $envPath;
    }

    $candidates[] = 'C:\\Program Files\\ShareX\\ffmpeg.exe';
    $candidates[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
    $candidates[] = 'ffmpeg';

    foreach ($candidates as $candidate) {
        if ($candidate === 'ffmpeg' || file_exists($candidate)) {
            return $candidate;
        }
    }

    return 'ffmpeg';
}

function dialecticRunFfmpeg(array $args)
{
    $ffmpeg = dialecticResolveFfmpegPath();
    $command = escapeshellarg($ffmpeg) . ' -v error -y';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $command .= ' 2>&1';

    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        dialecticLogFfmpegError("ffmpeg conversion failed ({$returnVar}): {$command} :: " . implode("\n", $output));
    }

    return $returnVar;
}

function dialecticNormalizeVoiceSampleToWav($inputFile, $outputFile, $inputFormat = '')
{
    if (!is_file($inputFile) || filesize($inputFile) <= 0) {
        return '';
    }

    $args = [];
    if ($inputFormat !== '') {
        $args[] = '-f';
        $args[] = $inputFormat;
    }
    $args[] = '-i';
    $args[] = $inputFile;
    $args[] = '-vn';
    $args[] = '-af';
    $args[] = 'highpass=f=45,lowpass=f=10000,loudnorm=I=-23:LRA=7:TP=-5,apad=whole_dur=5,atrim=duration=15';
    $args[] = '-ar';
    $args[] = '22050';
    $args[] = '-ac';
    $args[] = '1';
    $args[] = '-c:a';
    $args[] = 'pcm_s16le';
    $args[] = $outputFile;

    $returnVar = dialecticRunFfmpeg($args);
    return ($returnVar === 0 && is_file($outputFile) && filesize($outputFile) > 44) ? $outputFile : '';
}

function fuzToWav($fuzFileName)
{
    if (file_exists($fuzFileName)) {
        // Output Folder path
        $sOutputPath = dirname($fuzFileName);

        // Convert the *.fuz file to *.xwm
        $sOutputXwmFile = basename($fuzFileName, '.fuz') . '.wav';

        // Store the new full path for the xwm
        $sOutputXwm = $sOutputPath . DIRECTORY_SEPARATOR . $sOutputXwmFile;

        // Extract the xwm data from the fuz data file
        if (!file_exists($sOutputXwm) || true) {
            // fuz(e) file header format
            // 4 bytes = FUZE magic header
            // 4 bytes = unknown/unused, I suspect some kind of version number.
            // 4 bytes = Lip data size. Can be 0 or larger.
            // lip data (if Lip data size is larger than 0)
            // xwm data

            // Open Fuz file
            $fuzFile = fopen($fuzFileName, 'rb');

            // Get Fuze "Magic" header
            $fuzMagic = fread($fuzFile, 4);

            if ($fuzMagic === 'FUZE') {
                // Skip 4 offset bytes we don't need in the Fuz header
                fseek($fuzFile, 4, SEEK_CUR);

                // Read 4 bytes that contain the lip data size
                $fuzLipSizeData = fread($fuzFile, 4);
                $fuzLipSize = unpack('V', $fuzLipSizeData)[1];

                echo "Generating $sOutputXwmFile...\n";

                // if the Lip data size is larger than 0, the fuz file contains lip data.
                // we skip this data if needed.
                if ($fuzLipSize > 0) {
                    fseek($fuzFile, $fuzLipSize, SEEK_CUR);
                }

                // extract and write the xwm data stream to a temporary file
                $fuzFileSize = filesize($fuzFileName);
                $xwmDataLen = $fuzFileSize - $fuzLipSize - 12;

                $xwmData = fread($fuzFile, $xwmDataLen);

                $tmpXwmFile = tempnam(sys_get_temp_dir(), 'xwm');
                file_put_contents($tmpXwmFile, $xwmData);

                // Use ffmpeg to specify input format and convert the file
                
                $converted = dialecticNormalizeVoiceSampleToWav($tmpXwmFile, $sOutputXwm, 'xwma');

                if ($converted !== '') {
                    echo "Fuze Decode: success, xwm data written.\n";
                } else {
                    echo "Fuze Decode: failed, ffmpeg error.\n";
                }

                // Remove the temporary file
                unlink($tmpXwmFile);
            } else {
                echo "Fuze Decode: failed.\n";
                echo "$fuzFileName not a fuze file format!\n";
            }

            // Close file
            if ($fuzFile) {
                fclose($fuzFile);
            }
            return (is_file($sOutputXwm) && filesize($sOutputXwm) > 44) ? $sOutputXwm : '';
        } else {
            return "";
        }
        
    }
     return "";
}


function xwmToWav($fuzFileName)
{
    if (file_exists($fuzFileName)) {
        // Output Folder path
        $sOutputPath = dirname($fuzFileName);

        // Convert the *.fuz file to *.xwm
        $sOutputXwmFile = basename($fuzFileName, '.fuz') . '.wav';

        // Store the new full path for the xwm
        $sOutputXwm = $sOutputPath . DIRECTORY_SEPARATOR . $sOutputXwmFile;

        // Extract the xwm data from the fuz data file
        if (!file_exists($sOutputXwm) || true) {
            return dialecticNormalizeVoiceSampleToWav($fuzFileName, $sOutputXwm, 'xwma');
			
        } else {
            return "";
        }
        
    }
     return "";
}

function wavToWav($fuzFileName)
{
    if (file_exists($fuzFileName)) {
        // Output Folder path
        $sOutputPath = dirname($fuzFileName);

        // Convert the *.fuz file to *.xwm
        $sOutputXwmFile = pathinfo($fuzFileName, PATHINFO_FILENAME) . '_normalized.wav';

        // Store the new full path for the xwm
        $sOutputXwm = $sOutputPath . DIRECTORY_SEPARATOR . $sOutputXwmFile;

        // Extract the xwm data from the fuz data file
        if (!file_exists($sOutputXwm) || true) {
            return dialecticNormalizeVoiceSampleToWav($fuzFileName, $sOutputXwm);
			
        } else {
            return "";
        }
        
    }
     return "";
}

function oggToWav($oggFileName)
{
    if (file_exists($oggFileName)) {
        $sOutputPath = dirname($oggFileName);
        $sOutputWavFile = basename($oggFileName, '.ogg') . '.wav';
        $sOutputWav = $sOutputPath . DIRECTORY_SEPARATOR . $sOutputWavFile;

        return dialecticNormalizeVoiceSampleToWav($oggFileName, $sOutputWav);
    }

    return "";
}

// Example usage

?>





