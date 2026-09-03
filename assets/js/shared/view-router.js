

(function () {
  "use strict";

  function toPathname(absSrc) {
    try {
      return new URL(absSrc, window.location.href).pathname;
    } catch (e) {
      return String(absSrc);
    }
  }

  var loadedScriptSrcs = new Set();

  function seedLoadedScripts() {
    Array.prototype.forEach.call(
      document.querySelectorAll("script[src]"),
      function (s) {
        loadedScriptSrcs.add(toPathname(s.src));
      },
    );
  }
  seedLoadedScripts();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", seedLoadedScripts);
  } else {
    seedLoadedScripts();
  }

  
  
  var SESSION_TS = Date.now();

  function loadScriptOnce(absSrc) {
    var key = toPathname(absSrc);
    if (loadedScriptSrcs.has(key)) return Promise.resolve();
    loadedScriptSrcs.add(key);
    
    
    var buster = absSrc.indexOf("?") === -1 ? "?_meel=" : "&_meel=";
    var srcWithBuster = absSrc + buster + SESSION_TS;
    return new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = srcWithBuster;
      s.onload = function () {
        resolve();
      };
      s.onerror = function () {
        loadedScriptSrcs.delete(key);
        reject(new Error("Gagal memuat script: " + absSrc));
      };
      document.body.appendChild(s);
    });
  }

  function toAbsolute(relSrc) {
    return new URL(relSrc, window.location.href).href;
  }

  
  var loadedStyleHrefs = new Set(
    Array.prototype.map.call(
      document.querySelectorAll('link[rel="stylesheet"][href]'),
      function (l) {
        return l.href;
      },
    ),
  );

  
  
  
  function ensureViewStyles(doc) {
    var links = doc.querySelectorAll('link[rel="stylesheet"][href]');
    for (var i = 0; i < links.length; i++) {
      var absHref = toAbsolute(links[i].getAttribute("href"));
      if (loadedStyleHrefs.has(absHref)) continue;
      loadedStyleHrefs.add(absHref);
      var link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = absHref;
      document.head.appendChild(link);
    }
  }

  
  
  var DIRECT_SCRIPTS = {
    watch: [
      "../assets/js/shared/state-keys.js",
      "../assets/js/compatibilitas/plyr.min.js",
      "../assets/js/shared/keyboard.js",
      "../assets/js/shared/temp-index.js",
      "../assets/js/shared/plyr-config.js",
      "../assets/js/shared/format-time.js",
      "../assets/js/shared/resume-modal.js",
      "../assets/js/shared/mini-player-popstate.js",
      "../assets/js/shared/audio-engine.js",
      "../assets/js/shared/view-router.js",
      "../assets/js/shared/comment.js",
    ],
    index: [
      "../assets/js/shared/format-time.js",
      "../assets/js/shared/keyboard.js",
      "../assets/js/compatibilitas/plyr.min.js",
      "../assets/js/shared/plyr-config.js",
      "../assets/js/shared/audio-engine.js",
      "../assets/js/shared/view-router.js",
      "../assets/js/music/shared/mini-player.js",
    ],
  };
  var BUNDLE_GLOBAL = {
    watch: "MEEL_WATCH_BUNDLE",
    index: "MEEL_INDEX_BUNDLE",
  };
  var BUNDLE_LOADER_SRC = {
    watch: "../assets/js/music/watch/main.js",
    index: "../assets/js/music/index/main.js",
  };

  async function ensureViewScripts(viewType) {
    var directList = DIRECT_SCRIPTS[viewType] || [];
    for (var i = 0; i < directList.length; i++) {
      await loadScriptOnce(toAbsolute(directList[i]));
    }
    
    await loadScriptOnce(toAbsolute(BUNDLE_LOADER_SRC[viewType]));
    var bundleInfo = window[BUNDLE_GLOBAL[viewType]];
    if (bundleInfo && Array.isArray(bundleInfo.files)) {
      for (var j = 0; j < bundleInfo.files.length; j++) {
        await loadScriptOnce(bundleInfo.files[j]);
      }
    }
  }

  
  
  
  
  function runInlineScript(code) {
    var s = document.createElement("script");
    s.textContent = code;
    document.body.appendChild(s);
    
  }

  
  

  function parseConfigJson(text, varName) {
    try {
      var marker = "window." + varName + " =";
      var idx = text.indexOf(marker);
      if (idx === -1) return undefined;
      var open = text.indexOf("{", idx + marker.length);
      if (open === -1) return undefined;
      
      var depth = 0;
      var inStr = null;
      var end = -1;
      for (var i = open; i < text.length; i++) {
        var ch = text[i];
        if (inStr) {
          if (ch === "\\") {
            i++;
            continue;
          }
          if (ch === inStr) inStr = null;
          continue;
        }
        if (ch === '"' || ch === "'" || ch === "`") {
          inStr = ch;
          continue;
        }
        if (ch === "{") depth++;
        else if (ch === "}") {
          depth--;
          if (depth === 0) {
            end = i;
            break;
          }
        }
      }
      if (end === -1) return undefined;
      return JSON.parse(quoteObjectKeys(text.slice(open, end + 1)));
    } catch (e) {
      return undefined;
    }
  }

  
  
  function quoteObjectKeys(literal) {
    var out = "";
    var inStr = null;
    var ident = "";
    var canKey = false;
    for (var i = 0; i < literal.length; i++) {
      var ch = literal[i];
      if (inStr) {
        out += ch;
        if (ch === "\\") {
          out += literal[i + 1] || "";
          i++;
          continue;
        }
        if (ch === inStr) inStr = null;
        continue;
      }
      if (ch === '"' || ch === "'" || ch === "`") {
        inStr = ch;
        out += ch;
        canKey = false;
        ident = "";
        continue;
      }
      if (canKey) {
        if (ch === " " || ch === "\t" || ch === "\n" || ch === "\r") {
          out += ch; 
          continue;
        }
        if (/[A-Za-z_$0-9]/.test(ch)) {
          ident += ch;
          continue;
        }
        if (ch === ":" && ident.length) {
          out += '"' + ident + '"' + ch;
          ident = "";
          canKey = false;
          continue;
        }
        out += ident + ch;
        ident = "";
        canKey = false;
        continue;
      }
      if (ch === "{" || ch === ",") {
        canKey = true;
        out += ch;
        continue;
      }
      out += ch;
    }
    return out;
  }

  function applyInlineConfig(doc, viewType) {
    var varName = viewType === "watch" ? "MEEL_MUSIC_CONFIG" : "MEEL_INDEX_CONFIG";
    var scripts = doc.querySelectorAll("script:not([src])");
    for (var i = 0; i < scripts.length; i++) {
      var text = scripts[i].textContent || "";
      
      if (text.indexOf("window." + varName + " =") === -1) continue;
      try {
        runInlineScript(text);
      } catch (e) {
        console.error("❌ view-router: gagal eksekusi inline script:", e);
      }
      if (typeof window[varName] === "undefined") {
        var parsed = parseConfigJson(text, varName);
        if (parsed !== undefined) {
          window[varName] = parsed;
        } else {
          console.error("❌ view-router: gagal apply " + varName + " (injection & JSON fallback gagal)");
        }
      }
      return;
    }
  }

  






  window.meelNavigateView = async function (url, viewType, options) {
    options = options || {};
    try {
      var res = await fetch(url, { credentials: "same-origin" });
      if (!res.ok) throw new Error("HTTP " + res.status);
      var html = await res.text();
      var doc = new DOMParser().parseFromString(html, "text/html");

      
      ensureViewStyles(doc);

      
      
      var persisted = Array.prototype.slice.call(
        document.body.querySelectorAll("[data-meel-persist]"),
      );
      var holder = document.createDocumentFragment();
      persisted.forEach(function (n) {
        holder.appendChild(n);
      });

      
      
      
      
      
      var noscripts = doc.body.querySelectorAll("noscript");
      for (var ns = 0; ns < noscripts.length; ns++) {
        if (noscripts[ns].parentNode) noscripts[ns].parentNode.removeChild(noscripts[ns]);
      }
      
      document.body.innerHTML = "";
      Array.prototype.forEach.call(doc.body.children, function (node) {
        
        
        if (node.tagName === "SCRIPT" && node.src) return;
        document.body.appendChild(document.importNode(node, true));
      });

      
      document.body.appendChild(holder);

      
      
      
      var inlineScripts = document.body.querySelectorAll("script:not([src])");
      for (var k = 0; k < inlineScripts.length; k++) {
        runInlineScript(inlineScripts[k].textContent);
      }

      document.title = doc.title;
      applyInlineConfig(doc, viewType);

      
      await ensureViewScripts(viewType);

      
      if (options.pushState !== false) {
        window.history.pushState({ meelView: viewType }, "", url);
      }

      
      
      if (typeof options.onAfterSwap === "function") {
        options.onAfterSwap(doc);
      }

      if (window.lucide) window.lucide.createIcons();
      if (window.htmx) window.htmx.process(document.body);
      return true;
    } catch (err) {
      console.error("❌ view-router: navigasi AJAX gagal, fallback ke reload penuh:", err);
      
      window.location.href = url;
      return false;
    }
  };
})();
