/**
 * @file
 * kwtSMS dashboard bar chart using the HTML5 canvas API.
 *
 * Reads drupalSettings.kwtsms.dailyStats (array of {date, count}) and draws
 * a bar chart on #kwtsms-chart. Handles empty data gracefully.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.kwtsmsDashboard = {
    attach: function (context) {
      var canvas = context.querySelector
        ? context.querySelector('#kwtsms-chart')
        : document.getElementById('kwtsms-chart');

      if (!canvas || canvas.dataset.kwtsmsBound) {
        return;
      }
      canvas.dataset.kwtsmsBound = '1';

      var data = (drupalSettings.kwtsms && drupalSettings.kwtsms.dailyStats)
        ? drupalSettings.kwtsms.dailyStats
        : [];

      var ctx = canvas.getContext('2d');
      var W   = canvas.width;
      var H   = canvas.height;

      // Clear background.
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, W, H);

      if (!data.length) {
        ctx.fillStyle = '#999999';
        ctx.font = '16px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No data', W / 2, H / 2);
        return;
      }

      var padding = { top: 20, right: 20, bottom: 50, left: 50 };
      var chartW   = W - padding.left - padding.right;
      var chartH   = H - padding.top - padding.bottom;

      // Find the max count for scaling.
      var maxCount = 0;
      for (var i = 0; i < data.length; i++) {
        if (data[i].count > maxCount) {
          maxCount = data[i].count;
        }
      }
      if (maxCount === 0) { maxCount = 1; }

      var barCount = data.length;
      var barWidth = Math.floor(chartW / barCount) - 2;

      // Draw bars.
      for (var j = 0; j < barCount; j++) {
        var item     = data[j];
        var barH     = Math.round((item.count / maxCount) * chartH);
        var x        = padding.left + j * (barWidth + 2);
        var y        = padding.top + (chartH - barH);

        ctx.fillStyle = '#FFA200';
        ctx.fillRect(x, y, barWidth, barH);

        // Draw count label above bar if room.
        if (barH > 0) {
          ctx.fillStyle = '#434345';
          ctx.font = '11px sans-serif';
          ctx.textAlign = 'center';
          ctx.fillText(item.count, x + barWidth / 2, y - 3);
        }
      }

      // Draw x-axis date labels (show every ~5th to avoid clutter).
      var labelStep = Math.max(1, Math.ceil(barCount / 10));
      ctx.fillStyle = '#434345';
      ctx.font = '10px sans-serif';
      ctx.textAlign = 'center';

      for (var k = 0; k < barCount; k += labelStep) {
        var labelX = padding.left + k * (barWidth + 2) + barWidth / 2;
        var label  = data[k].date ? data[k].date.slice(5) : '';
        ctx.fillText(label, labelX, H - padding.bottom + 15);
      }

      // Draw baseline.
      ctx.strokeStyle = '#cccccc';
      ctx.lineWidth   = 1;
      ctx.beginPath();
      ctx.moveTo(padding.left, padding.top + chartH);
      ctx.lineTo(padding.left + chartW, padding.top + chartH);
      ctx.stroke();
    }
  };

})(Drupal, drupalSettings);
