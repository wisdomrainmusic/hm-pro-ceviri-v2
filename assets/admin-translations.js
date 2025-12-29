jQuery(function ($) {
  const data = window.HMPCv2Translations || {};

  function langLabel(code) {
    if (data.lang_labels && data.lang_labels[code]) return data.lang_labels[code];
    return code.toUpperCase();
  }

  function editLink(postId) {
    if (!postId) return "";
    return data.ajax_url.replace("admin-ajax.php", "post.php?post=" + postId + "&action=edit");
  }

  function setTab(tab) {
    $(".nav-tab").removeClass("nav-tab-active");
    $(".hmpcv2-tab").removeClass("active");
    $(".nav-tab[data-tab='" + tab + "']").addClass("nav-tab-active");
    $("#hmpcv2-tab-" + tab).addClass("active");
  }

  $(document).on("click", ".nav-tab", function (e) {
    e.preventDefault();
    setTab($(this).data("tab"));
  });

  setTab("content");

  $(".hmpcv2-suggested").each(function () {
    const $card = $(this);
    const mapData = $card.find(".hmpcv2-langs").data("map") || {};
    const postId = parseInt($card.data("post"), 10) || 0;
    const group = ($card.data("group") || "").toString();
    const item = {
      id: postId,
      group: { group: group, map: mapData },
      edit_urls: {}
    };
    Object.keys(mapData).forEach(function (code) {
      item.edit_urls[code] = editLink(mapData[code]);
    });
    $card.data("item", item);
  });

  function buildActions(item) {
    const map = item.group && item.group.map ? item.group.map : {};
    const groupId = item.group && item.group.group ? item.group.group : "";
    const baseId = map[data.default_lang] || item.id;
    let actions = "";

    if (!groupId) {
      actions +=
        '<button type="button" class="button button-small hmpcv2-create-group" data-source="' +
        baseId +
        '">Create group (base ' + data.default_lang.toUpperCase() + ")</button> ";
    }

    (data.enabled_langs || []).forEach(function (code) {
      if (map[code]) {
        if (item.edit_urls && item.edit_urls[code]) {
          actions +=
            '<a class="button button-small" href="' +
            item.edit_urls[code] +
            '">Edit ' +
            code.toUpperCase() +
            "</a> ";
        } else {
          actions +=
            '<a class="button button-small" href="' +
            editLink(map[code]) +
            '">Edit ' +
            code.toUpperCase() +
            "</a> ";
        }
      } else {
        actions +=
          '<button type="button" class="button button-small hmpcv2-create-translation" data-source="' +
          baseId +
          '" data-lang="' +
          code +
          '">Create ' +
          code.toUpperCase() +
          "</button> ";
      }
    });

    return actions;
  }

  function showNotice(message) {
    const $wrap = $(".wrap").first();
    if (!$wrap.length) {
      alert(message);
      return;
    }
    const $notice = $('<div class="notice notice-success is-dismissible"><p></p></div>');
    $notice.find("p").text(message);
    $wrap.prepend($notice);
    setTimeout(function () {
      $notice.fadeOut(200, function () {
        $notice.remove();
      });
    }, 2000);
  }

  function hmpcv2Nonce() {
    if (data && data.nonce) return data.nonce;
    if (window.HMPCV2 && HMPCV2.nonce) return HMPCV2.nonce;
    if (window.hmpcv2_admin && hmpcv2_admin.nonce) return hmpcv2_admin.nonce;
    if (window.HMPCV2_Admin && HMPCV2_Admin.nonce) return HMPCV2_Admin.nonce;
    return "";
  }

  function hmpcv2PostId($btn) {
    const $card = $btn.closest(".hmpcv2-card");
    const postId = $card.data("post");
    return parseInt(postId, 10) || 0;
  }

  function buildRow(item) {
    const map = item.group && item.group.map ? item.group.map : {};
    const pills = (data.enabled_langs || [])
      .map(function (code) {
        const cls = map[code] ? "ok" : "miss";
        return '<span class="hmpcv2-pill ' + cls + '\">' + code.toUpperCase() + "</span>";
      })
      .join(" ");

    const editUrl = item.edit_url ? '<a href="' + item.edit_url + '">' + item.title + "</a>" : item.title;

    const $row = $(
      "<tr class='hmpcv2-result-row'>" +
        "<td><strong>" + editUrl + "</strong><div class='hmpcv2-small'>#" + item.id + "</div></td>" +
        "<td>" + (item.type || "") + "</td>" +
        "<td>" + pills + "</td>" +
        "<td class='hmpcv2-actions-cell'></td>" +
      "</tr>"
    );

    $row.data("item", item);
    $row.find(".hmpcv2-actions-cell").html(buildActions(item));
    return $row;
  }

  function renderContentResults(items) {
    const $target = $("#hmpcv2-content-results");
    if (!items || !items.length) {
      $target.html("<p>No content found.</p>");
      return;
    }

    const $table = $('<table class="widefat striped hmpcv2-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table>');
    const $tbody = $table.find("tbody");

    items.forEach(function (item) {
      if (!item.edit_urls) item.edit_urls = {};
      if (item.group && item.group.map) {
        Object.keys(item.group.map).forEach(function (code) {
          const pid = item.group.map[code];
          item.edit_urls[code] = pid ? editLink(pid) : "";
        });
      }
      $tbody.append(buildRow(item));
    });

    $target.html($table);
  }

  $("#hmpcv2-content-form").on("submit", function (e) {
    e.preventDefault();
    const q = $("#hmpcv2-content-q").val();
    const $btn = $(this).find("button[type=submit]");
    $btn.prop("disabled", true).text("Searching…");
    $.post(data.ajax_url, {
      action: "hmpcv2_search_content",
      nonce: data.nonce,
      q: q
    })
      .done(function (res) {
        if (res && res.success && res.data && res.data.items) {
          renderContentResults(res.data.items);
        } else {
          $("#hmpcv2-content-results").html("<p>No content found.</p>");
        }
      })
      .fail(function () {
        $("#hmpcv2-content-results").html("<p>Error searching content.</p>");
      })
      .always(function () {
        $btn.prop("disabled", false).text("Search");
      });
  });

  $(document).on("click", ".hmpcv2-create-group", function () {
    const $btn = $(this);
    const $row = $btn.closest(".hmpcv2-result-row, .hmpcv2-suggested");
    const sourceId = parseInt($btn.data("source"), 10) || 0;
    if (!sourceId) return;

    $btn.prop("disabled", true).text("Creating…");
    $.post(data.ajax_url, {
      action: "hmpcv2_create_group",
      nonce: data.nonce,
      source_id: sourceId
    })
      .done(function (res) {
        if (res && res.success && res.data) {
          const item = $row.data("item") || {};
          item.group = { group: res.data.group, map: res.data.map };
          item.id = item.id || sourceId;
          item.edit_urls = item.edit_urls || {};
          Object.keys(res.data.map || {}).forEach(function (code) {
            const pid = res.data.map[code];
            item.edit_urls[code] = editLink(pid);
          });
          $row.data("item", item);
          $row.find(".hmpcv2-actions-cell").html(buildActions(item));
        } else {
          alert("Unable to create group");
        }
      })
      .fail(function () {
        alert("Unable to create group");
      })
      .always(function () {
        $btn.prop("disabled", false).text("Create group (base " + data.default_lang.toUpperCase() + ")");
      });
  });

  $(document).on("click", ".hmpcv2-create-translation", function () {
    const $btn = $(this);
    const sourceId = parseInt($btn.data("source"), 10) || 0;
    const lang = ($btn.data("lang") || "").toString();
    if (!sourceId || !lang) return;

    $btn.prop("disabled", true).text("Creating…");
    $.post(data.ajax_url, {
      action: "hmpcv2_create_translation",
      nonce: data.nonce,
      source_id: sourceId,
      target_lang: lang
    })
      .done(function (res) {
        if (res && res.success && res.data && res.data.new_id) {
          const $row = $btn.closest(".hmpcv2-result-row, .hmpcv2-suggested");
          const item = $row.data("item") || {};
          if (!item.group) item.group = { group: "", map: {} };
          if (!item.group.map) item.group.map = {};
          item.group.map = res.data.map || item.group.map;
          item.group.map[lang] = res.data.new_id;
          item.group.group = res.data.group || item.group.group;
          item.edit_urls = item.edit_urls || {};
          if (res.data.edit_url) item.edit_urls[lang] = res.data.edit_url;
          if (!item.edit_urls[lang]) item.edit_urls[lang] = editLink(res.data.new_id);
          $row.data("item", item);
          if ($row.find(".hmpcv2-actions-cell").length) {
            $row.find(".hmpcv2-actions-cell").html(buildActions(item));
          } else {
            $row.find(".hmpcv2-actions").replaceWith('<div class="hmpcv2-actions">' + buildActions(item) + "</div>");
          }
        } else {
          alert("Create failed");
        }
      })
      .fail(function () {
        alert("Create failed");
      })
      .always(function () {
        $btn.prop("disabled", false).text("Create " + lang.toUpperCase());
      });
  });

  $(document).on("click", '[data-action="hmpcv2-woo-title-edit"]', function (event) {
    event.preventDefault();

    const $btn = $(this);
    const pageId = hmpcv2PostId($btn);
    const lang = ($btn.data("lang") || "").toString().toLowerCase();
    const nonce = hmpcv2Nonce();
    const ajaxUrl = data.ajax_url || window.ajaxurl || "";

    if (!pageId || !lang) {
      alert("Missing page_id/lang");
      return;
    }

    $.post(ajaxUrl, {
      action: "hmpcv2_get_woo_page_title",
      nonce: nonce,
      page_id: pageId,
      lang: lang
    })
      .done(function (res) {
        if (!res || !res.success) {
          alert("Load failed");
          return;
        }

        const current = res.data && typeof res.data.title === "string" ? res.data.title : "";
        const nextTitle = window.prompt("Enter title for " + lang.toUpperCase(), current);

        if (nextTitle === null) return;

        $.post(ajaxUrl, {
          action: "hmpcv2_save_woo_page_title",
          nonce: nonce,
          page_id: pageId,
          lang: lang,
          title: nextTitle
        })
          .done(function (res2) {
            if (!res2 || !res2.success) {
              alert("Save failed");
              return;
            }
            alert("Saved");
          })
          .fail(function () {
            alert("Save request failed");
          });
      })
      .fail(function () {
        alert("Load request failed");
      });
  });

  function renderTermResults(items) {
    const $target = $("#hmpcv2-taxonomy-results");
    if (!items || !items.length) {
      $target.html("<p>No terms found.</p>");
      return;
    }

    const $wrap = $("<div></div>");
    items.forEach(function (term) {
      const $block = $('<div class="hmpcv2-term-block"></div>');
      $block.append('<strong>' + term.name + "</strong> <span class=\"hmpcv2-small\">#" + term.id + " — " + term.taxonomy + "</span>");
      if (term.description) {
        $block.append('<div class="hmpcv2-small" style="margin-top:4px;">' + term.description + "</div>");
      }

      const $grid = $('<div class="hmpcv2-term-grid"></div>');
      (data.enabled_langs || []).forEach(function (code) {
        const tr = term.translations && term.translations[code] ? term.translations[code] : {};
        const $col = $('<div class="field"></div>');
        $col.append('<label>' + code.toUpperCase() + " — " + langLabel(code) + "</label>");
        $col.append('<input type="text" class="hmpcv2-term-name" data-lang="' + code + '" value="' + (tr.name || "") + '" placeholder="Name" />');
        $col.append('<input type="text" class="hmpcv2-term-slug" data-lang="' + code + '" value="' + (tr.slug || "") + '" placeholder="Slug" />');
        $col.append('<textarea class="hmpcv2-term-description" data-lang="' + code + '" rows="3" placeholder="Description">' + (tr.description || "") + "</textarea>");
        $col.append('<button type="button" class="button hmpcv2-term-save" data-term="' + term.id + '" data-lang="' + code + '">Save ' + code.toUpperCase() + "</button>");
        $grid.append($col);
      });

      $block.append($grid);
      $wrap.append($block);
    });

    $target.html($wrap);
  }

  $("#hmpcv2-taxonomy-form").on("submit", function (e) {
    e.preventDefault();
    const q = $("#hmpcv2-taxonomy-q").val();
    const $btn = $(this).find("button[type=submit]");
    $btn.prop("disabled", true).text("Searching…");
    $.post(data.ajax_url, {
      action: "hmpcv2_search_terms",
      nonce: data.nonce,
      q: q
    })
      .done(function (res) {
        if (res && res.success && res.data && res.data.items) {
          renderTermResults(res.data.items);
        } else {
          $("#hmpcv2-taxonomy-results").html("<p>No terms found.</p>");
        }
      })
      .fail(function () {
        $("#hmpcv2-taxonomy-results").html("<p>Error searching terms.</p>");
      })
      .always(function () {
        $btn.prop("disabled", false).text("Search");
      });
  });

  $(document).on("click", ".hmpcv2-term-save", function () {
    const $btn = $(this);
    const lang = $btn.data("lang");
    const termId = parseInt($btn.data("term"), 10) || 0;
    const $block = $btn.closest(".field");
    if (!lang || !termId) return;

    const payload = {
      action: "hmpcv2_save_term_translation",
      nonce: data.nonce,
      term_id: termId,
      lang: lang,
      name: $block.find(".hmpcv2-term-name").val(),
      slug: $block.find(".hmpcv2-term-slug").val(),
      description: $block.find(".hmpcv2-term-description").val()
    };

    $btn.prop("disabled", true).text("Saving…");
    $.post(data.ajax_url, payload)
      .done(function (res) {
        if (res && res.success) {
          $btn.text("Saved");
          setTimeout(function () {
            $btn.text("Save " + lang.toUpperCase()).prop("disabled", false);
          }, 800);
        } else {
          alert("Save failed");
          $btn.prop("disabled", false).text("Save " + lang.toUpperCase());
        }
      })
      .fail(function () {
        alert("Save failed");
        $btn.prop("disabled", false).text("Save " + lang.toUpperCase());
      });
  });
});
