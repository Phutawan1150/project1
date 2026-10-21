function imageSrc(filename) {
    return /^https?:\/\//i.test(filename) ? filename : 'uploads/' + filename;
}

async function prepareImageForUpload(file) {
    if (!file.type.startsWith('image/') || !window.createImageBitmap) return file;
    try {
        const bitmap = await createImageBitmap(file);
        const maxDimension = 1200;
        const scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && file.size <= 700 * 1024) {
            bitmap.close();
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        if (!blob) return file;
        const baseName = file.name.replace(/\.[^.]+$/, '') || 'image';
        return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg' });
    } catch (_) {
        return file;
    }
}

async function uploadImages(input, accommodationId) {
    const status = document.getElementById(`upload-status-${accommodationId}`);
    const thumbs = document.getElementById(`thumbs-${accommodationId}`);
    const files = await Promise.all([...input.files].map(prepareImageForUpload));
    status.textContent = 'กำลังอัปโหลด...';

    const form = new FormData();
    form.append('accommodation_id', accommodationId);
    for (const file of files) form.append('images[]', file);

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 45000);
    try {
        const res = await fetch('upload_image.php', {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            signal: controller.signal
        });
        const responseText = await res.text();
        let data = {};
        try {
            data = responseText ? JSON.parse(responseText) : {};
        } catch (_) {
            data = {};
        }
        const responseHint = responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240);
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}${responseHint ? `: ${responseHint}` : ''}`);
        }
        if (!responseText || !data.saved) {
            throw new Error('เซิร์ฟเวอร์ตอบกลับไม่ใช่ข้อมูลอัปโหลดที่ระบบรองรับ');
        }

        data.saved.forEach(img => {
            thumbs.insertAdjacentHTML('beforeend', `
                <div class="image-thumb" id="thumb-${img.id}">
                    <img src="${imageSrc(img.filename)}" alt="">
                    <button type="button" class="image-thumb-delete" onclick="deleteImage(${img.id})" title="ลบรูปนี้">×</button>
                </div>`);
        });

        status.textContent = data.saved.length
            ? `อัปโหลดสำเร็จ ${data.saved.length} รูป` + (data.errors.length ? ` / ล้มเหลว: ${data.errors.join(', ')}` : '')
            : `ล้มเหลว: ${data.errors.join(', ')}`;
        input.value = '';
    } catch (error) {
        if (error.name === 'AbortError') {
            status.textContent = 'อัปโหลดนานเกิน 45 วินาที กรุณาลองใหม่ด้วยรูปขนาดเล็กลง';
        } else if (error.message.toLowerCase().includes('openresty')) {
            status.textContent = 'InfinityFree ปฏิเสธคำขออัปโหลดก่อนถึง PHP (403 openresty) ต้องให้ฝ่าย hosting ตรวจสอบการบล็อก POST ของ /upload_image.php';
        } else {
            status.textContent = 'อัปโหลดไม่สำเร็จ: ' + error.message;
        }
    } finally {
        clearTimeout(timeout);
    }
}

async function deleteImage(imageId) {
    if (!confirm('ลบรูปนี้ทิ้งเลยใช่ไหม?')) return;
    const form = new FormData();
    form.append('image_id', imageId);
    try {
        const res = await fetch('delete_image.php', { method: 'POST', body: form });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || `Server returned ${res.status}`);
        document.getElementById(`thumb-${imageId}`)?.remove();
    } catch (error) {
        alert('ลบรูปไม่สำเร็จ: ' + error.message);
    }
}

async function addImageUrls(accommodationId) {
    const input = document.getElementById(`url-input-${accommodationId}`);
    const status = document.getElementById(`upload-status-${accommodationId}`);
    const thumbs = document.getElementById(`thumbs-${accommodationId}`);
    const urls = input.value.trim();
    if (!urls) {
        status.textContent = 'กรุณาวางลิงก์รูปภาพก่อน';
        return;
    }
    status.textContent = 'กำลังเพิ่มรูป...';
    const form = new FormData();
    form.append('accommodation_id', accommodationId);
    form.append('urls', urls);
    try {
        const res = await fetch('add_image_url.php', { method: 'POST', body: form, credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        data.saved.forEach(img => {
            thumbs.insertAdjacentHTML('beforeend', `
                <div class="image-thumb" id="thumb-${img.id}">
                    <img src="${imageSrc(img.filename)}" alt="">
                    <button type="button" class="image-thumb-delete" onclick="deleteImage(${img.id})" title="ลบรูปนี้">×</button>
                </div>`);
        });
        status.textContent = data.saved.length
            ? `เพิ่มสำเร็จ ${data.saved.length} รูป` + (data.errors.length ? ` / ผิดพลาด: ${data.errors.join(', ')}` : '')
            : `ล้มเหลว: ${data.errors.join(', ')}`;
        if (data.saved.length) input.value = '';
    } catch (error) {
        status.textContent = 'เพิ่มรูปไม่สำเร็จ: ' + error.message;
    }
}
