(() => {
	const visor = document.getElementById('visor');
	const img = document.getElementById('visor-img');
	const titulo = document.getElementById('visor-titulo');
	const abrir = (fig) => {
		const original = fig.querySelector('.navegador__vista img');
		if (!original) return;
		img.src = original.currentSrc || original.src;
		img.alt = original.alt;
		const esMovil = fig.classList.contains('movil');
		img.style.width = esMovil ? Math.round(original.naturalWidth / 2) + 'px' : Math.min(original.naturalWidth, 1280) + 'px';
		titulo.textContent = original.alt;
		if (typeof visor.showModal === 'function') {
			visor.showModal();
		} else {
			window.open(img.src, '_blank');
		}
	};
	document.querySelectorAll('figure.captura').forEach((fig) => {
		fig.querySelector('.ampliar')?.addEventListener('click', () => abrir(fig));
		fig.querySelector('[data-ampliar] img')?.addEventListener('click', () => abrir(fig));
	});
	document.getElementById('visor-cerrar').addEventListener('click', () => visor.close());
	visor.addEventListener('click', (e) => { if (e.target === visor) visor.close(); });

	// Índice: resalta el apartado que se está leyendo.
	const enlaces = [...document.querySelectorAll('.indice a')];
	const secciones = enlaces.map((a) => document.querySelector(a.getAttribute('href'))).filter(Boolean);
	if ('IntersectionObserver' in window && secciones.length) {
		const obs = new IntersectionObserver((entradas) => {
			entradas.forEach((en) => {
				if (en.isIntersecting) {
					enlaces.forEach((a) => a.removeAttribute('aria-current'));
					const a = enlaces.find((x) => x.getAttribute('href') === '#' + en.target.id);
					if (a) a.setAttribute('aria-current', 'true');
				}
			});
		}, { rootMargin: '-20% 0px -70% 0px' });
		secciones.forEach((s) => obs.observe(s));
	}
})();
