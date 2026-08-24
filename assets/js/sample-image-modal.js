(function () {
  'use strict';

  var modal = null;
  var mainImage = null;
  var thumbs = null;
  var titleNode = null;
  var statusNode = null;
  var previousButton = null;
  var nextButton = null;
  var images = [];
  var activeIndex = 0;
  var returnFocus = null;

  function buildModal() {
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'sample-image-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<div class="sample-image-modal__backdrop" data-sample-image-close="1"></div>' +
      '<section class="sample-image-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sample-image-modal-title">' +
        '<header class="sample-image-modal__header">' +
          '<h2 id="sample-image-modal-title" class="sample-image-modal__title">サンプル画像</h2>' +
          '<button type="button" class="sample-image-modal__close" data-sample-image-close="1" aria-label="閉じる">×</button>' +
        '</header>' +
        '<div class="sample-image-modal__stage">' +
          '<button type="button" class="sample-image-modal__arrow sample-image-modal__arrow--prev" aria-label="前の画像">‹</button>' +
          '<img class="sample-image-modal__main" src="" alt="">' +
          '<button type="button" class="sample-image-modal__arrow sample-image-modal__arrow--next" aria-label="次の画像">›</button>' +
          '<p class="sample-image-modal__status" role="status"></p>' +
        '</div>' +
        '<div class="sample-image-modal__thumbs" aria-label="サンプル画像一覧"></div>' +
      '</section>';
    document.body.appendChild(modal);
    mainImage = modal.querySelector('.sample-image-modal__main');
    thumbs = modal.querySelector('.sample-image-modal__thumbs');
    titleNode = modal.querySelector('.sample-image-modal__title');
    statusNode = modal.querySelector('.sample-image-modal__status');
    previousButton = modal.querySelector('.sample-image-modal__arrow--prev');
    nextButton = modal.querySelector('.sample-image-modal__arrow--next');

    modal.addEventListener('click', function (event) {
      if (event.target.closest('[data-sample-image-close="1"]')) closeModal();
    });
    previousButton.addEventListener('click', function () { showImage(activeIndex - 1); });
    nextButton.addEventListener('click', function () { showImage(activeIndex + 1); });
  }

  function showImage(index) {
    if (!images.length) return;
    activeIndex = (index + images.length) % images.length;
    mainImage.src = images[activeIndex];
    mainImage.alt = 'サンプル画像 ' + (activeIndex + 1) + ' / ' + images.length;
    statusNode.textContent = (activeIndex + 1) + ' / ' + images.length;
    previousButton.hidden = images.length < 2;
    nextButton.hidden = images.length < 2;
    Array.prototype.forEach.call(thumbs.querySelectorAll('button'), function (button, buttonIndex) {
      button.classList.toggle('is-active', buttonIndex === activeIndex);
      button.setAttribute('aria-current', buttonIndex === activeIndex ? 'true' : 'false');
    });
  }

  function renderThumbs() {
    thumbs.innerHTML = '';
    images.forEach(function (url, index) {
      var button = document.createElement('button');
      var image = document.createElement('img');
      button.type = 'button';
      button.className = 'sample-image-modal__thumb';
      button.setAttribute('aria-label', '画像 ' + (index + 1) + ' を表示');
      image.src = url;
      image.alt = '';
      image.loading = 'lazy';
      button.appendChild(image);
      button.addEventListener('click', function () { showImage(index); });
      thumbs.appendChild(button);
    });
  }

  function openModal(trigger) {
    buildModal();
    returnFocus = trigger;
    images = [];
    thumbs.innerHTML = '';
    mainImage.removeAttribute('src');
    titleNode.textContent = trigger.dataset.sampleImagesTitle || 'サンプル画像';
    statusNode.textContent = '画像を読み込んでいます…';
    previousButton.hidden = true;
    nextButton.hidden = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sample-image-modal-open');
    modal.querySelector('.sample-image-modal__close').focus();

    fetch(trigger.dataset.sampleImagesUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('sample image request failed');
        return response.json();
      })
      .then(function (payload) {
        images = Array.isArray(payload.images) ? payload.images.filter(function (url) { return /^https?:\/\//i.test(url); }) : [];
        titleNode.textContent = payload.title || trigger.dataset.sampleImagesTitle || 'サンプル画像';
        if (!images.length) {
          statusNode.textContent = '表示できるサンプル画像がありません。';
          return;
        }
        renderThumbs();
        showImage(0);
      })
      .catch(function () {
        statusNode.textContent = 'サンプル画像を読み込めませんでした。時間をおいてもう一度お試しください。';
      });
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sample-image-modal-open');
    mainImage.removeAttribute('src');
    if (returnFocus) returnFocus.focus();
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('.sample-image-trigger');
    if (!trigger || !trigger.dataset.sampleImagesUrl) return;
    event.preventDefault();
    openModal(trigger);
  });
  document.addEventListener('keydown', function (event) {
    if (!modal || !modal.classList.contains('is-open')) return;
    if (event.key === 'Escape') closeModal();
    if (event.key === 'ArrowLeft') showImage(activeIndex - 1);
    if (event.key === 'ArrowRight') showImage(activeIndex + 1);
  });
}());
