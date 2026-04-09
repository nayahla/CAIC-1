/* ========= CAIC Landing Interactions ========= */

/* Announcement close */
(function () {
  const bar = document.querySelector("[data-announcement]");
  const close = document.querySelector("[data-announcement-close]");
  if (!bar || !close) return;
  close.addEventListener("click", () => bar.remove());
})();

/* Header search toggle */
(function () {
  document.querySelectorAll("[data-search]").forEach((form) => {
    const toggle = form.querySelector("[data-search-toggle]");
    const input = form.querySelector("[data-search-input]");
    if (!toggle || !input) return;

    function openSearch() {
      form.classList.add("is-open");
      toggle.setAttribute("aria-expanded", "true");
    }

    function closeSearch() {
      form.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
    }

    if (input.value.trim()) openSearch();

    toggle.addEventListener("click", (event) => {
      event.preventDefault();
      const isOpen = form.classList.contains("is-open");
      if (isOpen) {
        closeSearch();
      } else {
        openSearch();
        setTimeout(() => input.focus(), 120);
      }
    });

    form.addEventListener("submit", (event) => {
      if (!input.value.trim()) {
        event.preventDefault();
        openSearch();
        input.focus();
      }
    });

    input.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeSearch();
        toggle.focus();
      }
    });

    document.addEventListener("click", (event) => {
      if (!form.contains(event.target)) {
        closeSearch();
      }
    });
  });
})();

/* Force external links to open in a new tab */
(function () {
  function markExternalLink(link) {
    if (!(link instanceof HTMLAnchorElement)) return;

    const href = link.getAttribute("href");
    if (!href || href.startsWith("#") || link.hasAttribute("download")) return;

    let url;
    try {
      url = new URL(link.href, window.location.href);
    } catch {
      return;
    }

    if (!/^https?:$/i.test(url.protocol)) return;
    if (url.origin === window.location.origin) return;

    link.setAttribute("target", "_blank");

    const relTokens = new Set(
      (link.getAttribute("rel") || "")
        .split(/\s+/)
        .filter(Boolean)
    );
    relTokens.add("noopener");
    relTokens.add("noreferrer");
    link.setAttribute("rel", Array.from(relTokens).join(" "));
  }

  function scan(root = document) {
    root.querySelectorAll?.("a[href]").forEach(markExternalLink);
    if (root instanceof HTMLAnchorElement) {
      markExternalLink(root);
    }
  }

  scan();

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) {
          scan(node);
        }
      });
    });
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });
})();

function createDotPagination(container, count, onSelect) {
  if (!container) {
    return () => {};
  }

  const normalizedCount = Math.max(1, count);
  container.innerHTML = "";
  const dots = [];

  for (let i = 0; i < normalizedCount; i += 1) {
    const dot = document.createElement("button");
    dot.type = "button";
    dot.className = "caic-dot";
    dot.setAttribute("aria-label", `Go to page ${i + 1}`);
    dot.addEventListener("click", () => onSelect(i));
    container.appendChild(dot);
    dots.push(dot);
  }

  return (activeIndex) => {
    dots.forEach((dot, i) => {
      const active = i === activeIndex;
      dot.classList.toggle("is-active", active);
      dot.setAttribute("aria-current", active ? "true" : "false");
    });
  };
}

/* News carousel with dot pagination */
(function () {
  document.querySelectorAll("[data-carousel]").forEach((carousel) => {
    const track = carousel.querySelector("[data-carousel-track]");
    const viewport = carousel.querySelector(".caic-carouselViewport");
    const dotsWrap = carousel.querySelector("[data-carousel-dots]");
    if (!track || !viewport) return;

    const slides = Array.from(track.querySelectorAll("[data-carousel-slide]"));
    if (!slides.length) return;

    const intervalMs = parseInt(carousel.getAttribute("data-interval") || "5000", 10);
    const configuredVisible = Math.max(
      1,
      parseInt(carousel.getAttribute("data-visible") || "2", 10)
    );

    let pageIndex = 0;
    let pageCount = 1;
    let perPage = 1;
    let setActiveDot = () => {};
    let timer = null;

    function getPerPage() {
      return window.matchMedia("(max-width: 980px)").matches ? 1 : configuredVisible;
    }

    function syncPages() {
      perPage = getPerPage();
      pageCount = Math.max(1, Math.ceil(slides.length / perPage));
      pageIndex = Math.min(pageIndex, pageCount - 1);
      setActiveDot = createDotPagination(dotsWrap, pageCount, goToPage);
    }

    function render(animate = true) {
      const firstIndex = Math.min(slides.length - 1, pageIndex * perPage);
      const firstSlide = slides[firstIndex];
      const offset = firstSlide ? firstSlide.offsetLeft : 0;
      track.style.transition = animate ? "transform 0.55s ease" : "none";
      track.style.transform = `translateX(-${offset}px)`;
      setActiveDot(pageIndex);
    }

    function goToPage(nextPage, animate = true) {
      if (pageCount <= 1) return;
      pageIndex = ((nextPage % pageCount) + pageCount) % pageCount;
      render(animate);
    }

    function startAuto() {
      clearInterval(timer);
      if (intervalMs <= 0 || pageCount <= 1) return;
      timer = setInterval(() => goToPage(pageIndex + 1), intervalMs);
    }

    syncPages();
    render(false);
    startAuto();

    carousel.addEventListener("mouseenter", () => clearInterval(timer));
    carousel.addEventListener("mouseleave", startAuto);

    window.addEventListener("resize", () => {
      syncPages();
      render(false);
      startAuto();
    });
  });
})();

/* Resources pager with dot pagination */
(function () {
  document.querySelectorAll("[data-resource-pager]").forEach((pager) => {
    const track = pager.querySelector("[data-resource-track]");
    const dotsWrap = pager.querySelector("[data-resource-dots]") || pager.parentElement?.querySelector("[data-resource-dots]");
    if (!track) return;

    const cards = Array.from(track.children);
    if (!cards.length) return;

    const configuredVisible = 3;
    let index = 0;
    let pageCount = 1;
    let perView = 1;
    let setActiveDot = () => {};

    function render(animate = true) {
      const current = cards[index];
      const offset = current ? current.offsetLeft : 0;
      track.style.transition = animate ? "transform 0.45s ease" : "none";
      track.style.transform = `translateX(-${offset}px)`;
      setActiveDot(index);
    }

    function goTo(nextIndex, animate = true) {
      index = Math.max(0, Math.min(nextIndex, pageCount - 1));
      render(animate);
    }

    function syncPages() {
      perView = window.matchMedia("(max-width: 980px)").matches ? 1 : configuredVisible;
      pageCount = Math.max(1, cards.length - perView + 1);
      index = Math.min(index, pageCount - 1);
      setActiveDot = createDotPagination(dotsWrap, pageCount, (i) => goTo(i));
    }

    syncPages();
    render(false);

    window.addEventListener("resize", () => {
      syncPages();
      render(false);
    });
  });
})();

/* FAQ Accordion: single open + highlight */
(function () {
  document.querySelectorAll("[data-accordion]").forEach((acc) => {
    const items = acc.querySelectorAll(".caic-accItem");
    items.forEach((item) => {
      const btn = item.querySelector(".caic-accBtn");
      const panel = item.querySelector(".caic-accPanel");
      if (!btn || !panel) return;

      btn.addEventListener("click", () => {
        const isOpen = item.classList.contains("is-open");

        // close all
        items.forEach(i => {
          i.classList.remove("is-open");
          const b = i.querySelector(".caic-accBtn");
          const p = i.querySelector(".caic-accPanel");
          b?.setAttribute("aria-expanded", "false");
          if (p) p.hidden = true;
        });

        // open clicked if it wasn't open
        if (!isOpen) {
          item.classList.add("is-open");
          btn.setAttribute("aria-expanded", "true");
          panel.hidden = false;
        }
      });
    });
  });
})();

/* Scroll reveal */
(function () {
  document.querySelectorAll("main > section").forEach((section) => {
    if (section.classList.contains("reveal") || section.querySelector(".reveal")) {
      return;
    }
    section.classList.add("reveal");
  });

  const els = document.querySelectorAll(".reveal");
  if (!els.length) return;

  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add("visible");
        obs.unobserve(e.target);
      }
    });
  }, { threshold: 0.15 });

  els.forEach(el => obs.observe(el));
})();
