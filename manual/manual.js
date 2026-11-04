(() => {
	document.documentElement.classList.add('js');

	/* Visor de capturas */
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

	/* Un apartado cada vez: el índice lleva a cada uno y «Ver todo seguido» los junta */
	const raiz = document.documentElement;
	const secciones = [...document.querySelectorAll('main > section')].filter((s) => !s.classList.contains('otros'));
	if (!secciones.length) return;

	const guardado = (clave, valor) => {
		try {
			if (valor === undefined) return localStorage.getItem(clave);
			localStorage.setItem(clave, valor);
		} catch (e) { /* sin almacenamiento: no pasa nada */ }
		return null;
	};

	// Anterior / siguiente al pie de cada apartado.
	secciones.forEach((s, i) => {
		const nav = document.createElement('nav');
		nav.className = 'pager';
		nav.setAttribute('aria-label', 'Anterior y siguiente');
		const enlace = (otra, clase, texto) => {
			const t = otra.querySelector('h2');
			const a = document.createElement('a');
			a.href = '#' + otra.id;
			a.className = clase;
			a.textContent = texto.replace('%', (t ? t.textContent.replace(/\s+/g, ' ').trim() : otra.id));
			return a;
		};
		if (i > 0) nav.appendChild(enlace(secciones[i - 1], 'anterior', '← %'));
		if (i < secciones.length - 1) nav.appendChild(enlace(secciones[i + 1], 'siguiente', '% →'));
		if (nav.children.length) s.appendChild(nav);
	});

	// Apartados largos: un apartado con varios subapartados (h3) se reparte en hojas, una a la vista cada vez.
	let contador = 0;
	secciones.forEach((s) => {
		const h3s = [...s.children].filter((e) => e.tagName === 'H3');
		if (h3s.length < 3) return;
		const hojas = [];
		let grupo = document.createElement('div');
		grupo.className = 'hoja intro';
		const primero = h3s[0];
		// Lo que va antes del primer subapartado (menos el título) es la introducción y se ve siempre.
		[...s.children].forEach((e) => {
			if (e.tagName === 'H2' || e.classList.contains('pager')) return;
			if (e === primero || h3s.includes(e)) {
				if (grupo.childNodes.length || grupo.classList.contains('intro')) hojas.push(grupo);
				grupo = document.createElement('div');
				grupo.className = 'hoja';
				if (!e.id) e.id = 'sub-' + (++contador);
				grupo.dataset.hoja = e.id;
			}
			grupo.appendChild(e);
		});
		hojas.push(grupo);
		const h2 = s.querySelector('h2');
		const pestanas = document.createElement('nav');
		pestanas.className = 'pestanas';
		pestanas.setAttribute('aria-label', 'Partes de este apartado');
		hojas.forEach((h) => {
			s.insertBefore(h, s.querySelector('.pager'));
			if (!h.dataset.hoja) return;
			const a = document.createElement('a');
			a.href = '#' + h.dataset.hoja;
			a.textContent = h.querySelector('h3').textContent.replace(/\s+/g, ' ').trim();
			pestanas.appendChild(a);
		});
		if (h2) h2.after(pestanas);
		s.classList.add('con-hojas');
	});

	const mostrarHoja = (seccion, hoja) => {
		if (!seccion.classList.contains('con-hojas')) return;
		const hojas = [...seccion.querySelectorAll(':scope > .hoja[data-hoja]')];
		const activa = hoja || hojas[0];
		hojas.forEach((h) => h.classList.toggle('activa', h === activa));
		seccion.querySelectorAll(':scope > .pestanas a').forEach((a) => {
			const esta = a.getAttribute('href') === '#' + activa.dataset.hoja;
			if (esta) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
		});
	};

	const marcar = (activa) => {
		secciones.forEach((s) => s.classList.toggle('activa', s === activa));
		// Índice: el apartado a la vista y, debajo, solo sus subapartados.
		let abierta = false;
		document.querySelectorAll('.indice li').forEach((li) => {
			const a = li.querySelector('a');
			if (!li.classList.contains('sub')) {
				abierta = a && a.getAttribute('href') === '#' + activa.id;
				if (a) { if (abierta) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); }
			} else {
				li.classList.toggle('visible', abierta);
			}
		});
	};

	const irA = (hash, desplazar) => {
		const id = decodeURIComponent((hash || '').replace(/^#/, ''));
		const destino = id ? document.getElementById(id) : null;
		const seccion = destino ? destino.closest('main > section') : null;
		const activa = seccion && secciones.includes(seccion) ? seccion : secciones[0];
		marcar(activa);
		const hoja = destino ? destino.closest('.hoja[data-hoja]') : null;
		mostrarHoja(activa, hoja && activa.contains(hoja) ? hoja : null);
		if (!desplazar) return;
		if (destino && destino !== activa && activa.contains(destino)) {
			destino.scrollIntoView();
		} else {
			window.scrollTo(0, 0);
		}
	};

	// Botón: un apartado cada vez / todo seguido.
	const boton = document.createElement('button');
	boton.type = 'button';
	boton.className = 'modo';
	const pintarBoton = () => { boton.textContent = raiz.classList.contains('todo') ? 'Ver un apartado cada vez' : 'Ver todo seguido'; };
	boton.addEventListener('click', () => {
		const activo = secciones.find((s) => s.classList.contains('activa'));
		raiz.classList.toggle('todo');
		guardado('manual-todo', raiz.classList.contains('todo') ? '1' : '0');
		pintarBoton();
		if (activo) activo.scrollIntoView();
	});
	if (guardado('manual-todo') === '1') raiz.classList.add('todo');
	pintarBoton();
	document.querySelector('.indice h2')?.after(boton);

	window.addEventListener('hashchange', () => irA(location.hash, true));
	irA(location.hash, false);
	if (location.hash) {
		const destino = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		if (destino) destino.scrollIntoView();
	}

	// Con «todo seguido», el índice resalta lo que se está leyendo.
	if ('IntersectionObserver' in window) {
		const obs = new IntersectionObserver((entradas) => {
			if (!raiz.classList.contains('todo')) return;
			entradas.forEach((en) => { if (en.isIntersecting) marcar(en.target); });
		}, { rootMargin: '-15% 0px -75% 0px' });
		secciones.forEach((s) => obs.observe(s));
	}
})();
