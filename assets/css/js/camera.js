let videoStream = null;

function initCamera(videoElemId, canvasElemId) {
    const video = document.getElementById(videoElemId);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Camera API not supported on this browser.');
        return;
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then((stream) => {
            videoStream = stream;
            video.srcObject = stream;
        })
        .catch((err) => {
            console.error("Camera access denied: ", err);
        });
}

function captureAndCompressPhoto(videoElemId, canvasElemId, maxWidth = 800) {
    const video = document.getElementById(videoElemId);
    const canvas = document.getElementById(canvasElemId);
    const ctx = canvas.getContext('2d');

    const ratio = video.videoWidth / video.videoHeight;
    canvas.width = maxWidth;
    canvas.height = maxWidth / ratio;

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Returns compressed JPEG base64 string
    return canvas.toDataURL('image/jpeg', 0.7);
}

function stopCamera() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
    }
}