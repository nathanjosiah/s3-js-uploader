<?php

use Aws\Sts\StsClient;

include __DIR__ . '/vendor/autoload.php';

$sts = new StsClient([
    'version' => 'latest',
    'region' => 'us-west-2'
]);
$session = $sts->assumeRole([
    'DurationSeconds' => 3600,
    'Policy' => <<<S3POLICY
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "s3:PutObject",
            "Resource": "arn:aws:s3:::nathanjosiah/drop/*"
        }
    ]
}
S3POLICY
    ,
    'RoleArn' => 'arn:aws:iam::688584915753:role/s3-full-access',
    'RoleSessionName' => 's3-upload-role'

]);

?>
<!doctype html>
<html>
<head>
    <title>S3 Upload Example</title>
    <style>
        #file-info {
            background-color: #EEE;
            border-radius: 9px;
            color: #666;
            font-family: Arimo, "Helvetica Neue", Helvetica, Arial, sans-serif;
            margin: 0 0 10px 0;
            padding: 10px;
        }
        #progress-info {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            font-size: 14px;
            left: -10000px;
            margin: 0 0 10px 0;
            position: absolute;
        }
        #progress-info .progress-meta {
            color: #666666;
            flex: 1;
            font-family: Arimo, "Helvetica Neue", Helvetica, Arial, sans-serif;
            display: flex;
            justify-content: space-between;
        }
        #progress-bar-wrapper {
            background-color: #979898;
            border-radius: 10px;
            margin: 6px 0;
            overflow: hidden;
            width: 100%;
        }
        #progress-bar {
            background: #F0253D;
            height: 20px;
            width: 0;
        }
        #uploader-wrapper {
            position: relative;
        }
        .dragging-file #uploader-wrapper:before {
            align-items: center;
            background: #EEEEEE;
            border: 2px dashed #666;
            color: #666;
            content: 'Drop file here';
            display: flex;
            font-family: Arimo, "Helvetica Neue", Helvetica, Arial, sans-serif;
            height: 100%;
            justify-content: center;
            left: 0;
            line-height: 0;
            position: absolute;
            top: 0;
            width: 100%;
        }
    </style>
</head>
<body>
<div id="uploader-wrapper">
    <div id="file-info">No file selected</div>
    <div id="progress-info">
        <div class="progress-meta">
            <span id="percent"></span>
            <span id="data-transferred"></span>
        </div>
        <div id="progress-bar-wrapper">
            <div id="progress-bar"></div>
        </div>
        <div class="progress-meta">
            <span id="transfer-rate"></span>
            <span id="time-left"></span>
        </div>
    </div>
    <button id="choose-file">Choose File</button>
    <button id="upload" disabled>Upload</button>
</div>
<script src="https://sdk.amazonaws.com/js/aws-sdk-2.466.0.min.js"></script>
<script>
    AWS.config.update({
        credentials: new AWS.Credentials(<?=json_encode([
            'accessKeyId'    => $session['Credentials']['AccessKeyId'],
            'secretAccessKey' => $session['Credentials']['SecretAccessKey'],
            'sessionToken'  => $session['Credentials']['SessionToken']
        ])?>)
    });

    function SizeFormatter() {

    }

    SizeFormatter.prototype = {
        format: function(size) {
            if (size > 1024 * 1024 * 1024) {
                return (Math.round(size * 100 / (1024 * 1024 * 1024)) / 100).toString() + ' GB';
            } else if (size > 1024 * 1024) {
                return (Math.round(size * 100 / (1024 * 1024)) / 100).toString() + ' MB';
            }

            return (Math.round(size * 100 / 1024) / 100).toString() + ' KB';
        }
    };

    function FileInfo(element, sizeFormatter) {
        this.element = element;
        this.sizeFormatter = sizeFormatter;
    }
    FileInfo.prototype = {
        setFile: function(file) {
            var fileSize;

            if (!Boolean(file)) {
                this.element.textContent = 'No file selected.';
                return;
            }

            fileSize = this.sizeFormatter.format(file.size);

            this.element.textContent = file.name + ' (' + fileSize + ')';
        }
    };

    function FileChooser() {

    }
    FileChooser.prototype = {
        promptForFile: function() {
            return new Promise((resolve, reject) => {
                var input = document.createElement('input');
                input.type = 'file';

                input.onchange = e => {
                    var file = e.target.files[0];
                    if (file) {
                        resolve(file);
                    }
                };

                input.click();
            });
        }
    };

    function ProgressUpdater(
        percentageElement,
        progressBarElement,
        timeLeftElement,
        transferRateElement,
        dataTransferredElement,
        sizeFormatter
    ) {
        this.percentageElement = percentageElement;
        this.progressBarElement = progressBarElement;
        this.timeLeftElement = timeLeftElement;
        this.transferRateElement = transferRateElement;
        this.dataTransferredElement = dataTransferredElement;
        this.sizeFormatter = sizeFormatter;
    }

    ProgressUpdater.prototype = {
        setPercentComplete: function (percent) {
            percent = Math.round(percent * 10000) / 100;
            this.percentageElement.textContent = percent + '% ';
            this.progressBarElement.style.width = percent + '%';
        },

        setDataTransferRate: function (rate) {
            this.transferRateElement.textContent = this.sizeFormatter.format(rate) + '/s'
        },

        setDataTransferred: function (uploaded, left) {
            this.dataTransferredElement.textContent = this.sizeFormatter.format(uploaded)
                + ' of '
                + this.sizeFormatter.format(left);
        },

        setTimeLeft: function (seconds) {
            var timeLeft;

            if (seconds > 60 * 60) {
                timeLeft = parseInt(seconds / 60 / 60) + ' hours '
                    + parseInt(seconds / 60 % 60) + ' minutes '
                    + parseInt(seconds % 60) + ' seconds';
            } else if (seconds > 60) {
                timeLeft = parseInt(seconds / 60 % 60) + ' minutes '
                    + parseInt(seconds % 60) + ' seconds ';
            } else {
                timeLeft = parseInt(seconds) + ' seconds ';
            }

            this.timeLeftElement.textContent = timeLeft + ' left';
        }
    };

    function FileUploader(s3, progressUpdater) {
        this.s3 = s3;
        this.progressUpdater = progressUpdater;
    }

    FileUploader.prototype = {
        upload: function(file) {
            this.startTime = new Date().getTime();
            return new Promise((resolve, reject) => {
                this.s3.upload({
                    Body: file,
                    Bucket: 'nathanjosiah',
                    Key: 'drop/foobar.file'
                })
                    .on('httpUploadProgress', this._onUploadProgress.bind(this))
                    .promise()
                    .then(resolve)
                    .catch(reject);
            });
        },

        _onUploadProgress: function(progress) {
            var elapsedSeconds = (new Date().getTime() - this.startTime) / 1000,
                uploadedPerSecond = progress.loaded / elapsedSeconds,
                secondsLeft = (progress.total - progress.loaded) / uploadedPerSecond;

            this.progressUpdater.setPercentComplete(progress.loaded / progress.total);
            this.progressUpdater.setTimeLeft(secondsLeft);
            this.progressUpdater.setDataTransferRate(uploadedPerSecond);
            this.progressUpdater.setDataTransferred(progress.loaded, progress.total);
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        var s3 = new AWS.S3({
                apiVersion: '2006-03-01',
                params: {Bucket: 'nathanjosiah'},
            }),
            sizeFormatter = new SizeFormatter(),
            fileInfoElement = document.getElementById('file-info'),
            fileInfo = new FileInfo(fileInfoElement, sizeFormatter),
            progressInfoElement = document.getElementById('progress-info'),
            fileChooser = new FileChooser(),
            progressUpdater = new ProgressUpdater(
                document.getElementById('percent'),
                document.getElementById('progress-bar'),
                document.getElementById('time-left'),
                document.getElementById('transfer-rate'),
                document.getElementById('data-transferred'),
                sizeFormatter
            ),
            fileUploader = new FileUploader(s3, progressUpdater),
            chooserButton = document.getElementById('choose-file'),
            uploadButton = document.getElementById('upload'),
            selectedFile,
            uploading = false;

        function setFile(file) {
            if (file && !/\.mp4$/.test(file.name.trim())) {
                alert('Invalid file! Only MP4 file type is currently supported.');
                return;
            }
            selectedFile = file;
            fileInfo.setFile(file);
            uploadButton.disabled = !Boolean(file);
        }

        chooserButton.addEventListener('click', function () {
            fileChooser
            .promptForFile()
            .then(setFile);
        });

        uploadButton.addEventListener('click', function () {
            if (selectedFile) {
                chooserButton.disabled = true;
                uploadButton.disabled = true;
                progressInfoElement.style.position = 'static';
                uploading = true;
                fileUploader.upload(selectedFile)
                .then(() => {
                    chooserButton.disabled = false;
                    uploadButton.disabled = false;
                    progressInfoElement.style.position = 'absolute';
                    setFile(null);
                    fileInfoElement.textContent = 'File was successfully uploaded!';
                    uploading = false;
                })
                .catch(() => {
                    chooserButton.disabled = false;
                    uploadButton.disabled = false;
                    progressInfoElement.style.position = 'absolute';
                    uploading = false;
                });
            }
        });

        window.addEventListener('drop', (e) => {
            e.preventDefault();
            if (uploading) {
                 return;
            }
            document.body.classList.remove('dragging-file');
            if (e.dataTransfer.items[0].kind !== 'file') {
                return;
            }
            setFile(e.dataTransfer.items[0].getAsFile());
        });
        window.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (uploading) {
                 return;
            }
            document.body.classList.add('dragging-file');
        });
        window.addEventListener('dragleave', (e) => {
            e.preventDefault();
            if (uploading) {
                 return;
            }
            document.body.classList.remove('dragging-file');
        });
    });
</script>
</body>
</html>