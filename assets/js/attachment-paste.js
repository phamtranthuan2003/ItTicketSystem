(() => {
  const input = document.getElementById('ticket-attachment');
  const pasteArea = document.getElementById('attachment-paste-area');
  const preview = document.getElementById('attachment-preview');
  const previewList = document.getElementById('attachment-preview-list');
  const feedback = document.getElementById('attachment-feedback');
  const removeButton = document.getElementById('attachment-remove');
  const maxBytes = 5 * 1024 * 1024;
  const maxFiles = 5;
  const allowedTypes = new Map([
    ['image/jpeg', 'jpg'],
    ['image/png', 'png'],
    ['image/webp', 'webp']
  ]);
  const previewUrls = new Map();
  let selectedFiles = [];

  if (!input || !pasteArea || !preview || !previewList || !feedback || !removeButton) {
    return;
  }

  function currentFiles() {
    return Array.from(input.files || []);
  }

  function setFiles(files) {
    const transfer = new DataTransfer();
    files.forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
  }

  function renderFiles(files) {
    selectedFiles = files;
    const retainedFiles = new Set(files);
    for (const [file, url] of previewUrls) {
      if (!retainedFiles.has(file)) {
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        previewUrls.delete(file);
      }
    }
    previewList.replaceChildren();
    preview.hidden = files.length === 0;
    pasteArea.classList.toggle('has-file', files.length > 0);

    files.forEach((file, index) => {
      const item = document.createElement('div');
      item.className = 'attachment-preview-item';
      if (file.type.startsWith('image/') && allowedTypes.has(file.type)) {
        const image = document.createElement('img');
        image.alt = `Ảnh đính kèm ${index + 1}`;
        let url = previewUrls.get(file);
        if (!url) {
          url = URL.createObjectURL(file);
          previewUrls.set(file, url);
        }
        image.src = url;
        item.appendChild(image);
      }

      const details = document.createElement('div');
      details.className = 'attachment-file-info';
      const name = document.createElement('strong');
      name.textContent = file.name;
      const size = document.createElement('span');
      size.textContent = `${(file.size / (1024 * 1024)).toFixed(2)} MB`;
      details.append(name, size);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'attachment-remove-one';
      remove.textContent = 'Bỏ';
      remove.setAttribute('aria-label', `Bỏ tệp ${file.name}`);
      remove.addEventListener('click', () => {
        const nextFiles = selectedFiles.slice();
        nextFiles.splice(index, 1);
        setFiles(nextFiles);
        renderFiles(nextFiles);
        feedback.textContent = nextFiles.length
          ? `Còn ${nextFiles.length} tệp đính kèm.`
          : 'Đã bỏ tệp đính kèm. Bạn có thể chọn tệp khác hoặc dán ảnh mới.';
      });

      item.append(details, remove);
      previewList.appendChild(item);
    });
  }

  function validateFiles(files) {
    if (files.length > maxFiles) {
      return 'Bạn chỉ có thể đính kèm tối đa 5 tệp.';
    }
    if (files.some((file) => file.size > maxBytes)) {
      return 'Mỗi tệp đính kèm không được vượt quá 5MB.';
    }
    if (files.some((file) => file.type.startsWith('image/') && !allowedTypes.has(file.type))) {
      return 'Ảnh phải có định dạng JPG, PNG hoặc WEBP.';
    }
    return '';
  }

  input.addEventListener('change', () => {
    const files = currentFiles();
    const error = validateFiles(files);
    feedback.classList.toggle('is-error', Boolean(error));
    if (error) {
      setFiles([]);
      renderFiles([]);
      feedback.textContent = error;
      return;
    }
    renderFiles(files);
    feedback.textContent = files.length ? `Đã chọn ${files.length} tệp đính kèm.` : 'Có thể dán một hoặc nhiều ảnh bằng Ctrl + V, hoặc chọn tệp từ thiết bị.';
  });

  document.addEventListener('paste', (event) => {
    const imageItems = Array.from(event.clipboardData?.items || [])
      .filter((item) => item.kind === 'file' && item.type.startsWith('image/'));
    if (!imageItems.length) {
      return;
    }

    const images = imageItems
      .filter((item) => allowedTypes.has(item.type))
      .map((item) => item.getAsFile())
      .filter((file) => file !== null);
    event.preventDefault();

    if (images.length !== imageItems.length) {
      feedback.classList.add('is-error');
      feedback.textContent = 'Chỉ hỗ trợ ảnh JPG, PNG hoặc WEBP.';
      return;
    }

    const timestamp = Date.now();
    const pastedFiles = images.map((image, index) => {
      const extension = allowedTypes.get(image.type);
      return new File([image], `screenshot-${timestamp}-${index + 1}.${extension}`, { type: image.type });
    });
    const nextFiles = selectedFiles.concat(pastedFiles);
    const error = validateFiles(nextFiles);
    feedback.classList.toggle('is-error', Boolean(error));
    if (error) {
      feedback.textContent = error;
      return;
    }

    setFiles(nextFiles);
    renderFiles(nextFiles);
    feedback.textContent = `Đã thêm ${pastedFiles.length} ảnh. Tổng cộng ${nextFiles.length} tệp đính kèm.`;
  });

  pasteArea.addEventListener('click', (event) => {
    if (event.target !== input) {
      input.click();
    }
  });

  pasteArea.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      input.click();
    }
  });

  removeButton.addEventListener('click', () => {
    setFiles([]);
    renderFiles([]);
    feedback.classList.remove('is-error');
    feedback.textContent = 'Đã bỏ tất cả tệp đính kèm. Bạn có thể chọn tệp khác hoặc dán ảnh mới.';
    pasteArea.focus();
  });

  window.addEventListener('pagehide', () => {
    previewUrls.forEach((url) => URL.revokeObjectURL(url));
    previewUrls.clear();
  });
})();
