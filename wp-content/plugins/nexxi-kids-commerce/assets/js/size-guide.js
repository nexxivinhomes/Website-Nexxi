(() => {
  const labels = window.NexxiSizeGuide?.labels || {};
  document.querySelectorAll('[data-nexxi-size-guide]').forEach(guide => {
    const height = guide.querySelector('[data-height]');
    const weight = guide.querySelector('[data-weight]');
    const result = guide.querySelector('[data-size-result]');
    const submit = guide.querySelector('[data-size-submit]');
    if (!height || !weight || !result || !submit) return;
    submit.addEventListener('click', () => {
      const h = Number(height.value), w = Number(weight.value);
      result.hidden = false;
      result.classList.remove('is-error');
      if (!h || !w || h < 75 || h > 170 || w < 8 || w > 55) {
        result.textContent = labels.enter || 'Vui lòng nhập chiều cao và cân nặng của bé.';
        result.classList.add('is-error');
        return;
      }
      const chart = [[1,8,10,75],[2,10,12,87],[3,12,14,96],[4,14,16,104],[5,16,18,110],[6,18,20,116],[7,20,22,122],[8,22,24,128],[9,24,27,134],[10,27,30,140],[11,30,33,146],[12,33,36,152],[13,36,40,156],[14,40,45,160],[15,45,50,165],[16,50,55,170]];
      let byHeight = chart[0][0], heightGap = Infinity;
      let byWeight = chart[0][0], weightGap = Infinity;
      chart.forEach(([size, minWeight, maxWeight, targetHeight]) => {
        const hGap = Math.abs(h - targetHeight);
        if (hGap < heightGap || (hGap === heightGap && size > byHeight)) { heightGap = hGap; byHeight = size; }
        const wGap = w < minWeight ? minWeight - w : w > maxWeight ? w - maxWeight : 0;
        if (wGap < weightGap || (wGap === weightGap && size > byWeight)) { weightGap = wGap; byWeight = size; }
      });
      const size = Math.max(byHeight, byWeight);
      result.replaceChildren();
      const strong = document.createElement('strong');
      strong.textContent = `${labels.result || 'Size gợi ý cho bé:'} ${size}`;
      const hint = document.createElement('small');
      hint.textContent = labels.hint || '';
      result.append(strong, hint);
    });
  });
})();
