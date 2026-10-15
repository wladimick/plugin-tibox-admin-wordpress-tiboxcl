/* Tibox Admin · selector de imágenes de la biblioteca de medios para el slider */
(function ($) {
  'use strict';
  $(document).on('click', '.tbx-pick', function (e) {
    e.preventDefault();
    var input = $(this).siblings('input');
    var frame = wp.media({ title: 'Elegir imagen del slide', button: { text: 'Usar esta imagen' }, library: { type: 'image' }, multiple: false });
    frame.on('select', function () {
      var url = frame.state().get('selection').first().toJSON().url;
      input.val(url).trigger('change');
      var preview = input.closest('.tbx-slide').find('.tbx-slide__preview');
      if (input.attr('name').indexOf('[image]') !== -1) {
        preview.css('background-image', 'url(' + url + ')');
      }
    });
    frame.open();
  });
})(jQuery);
