jQuery(function ($) {
  function getHiddenField($input) {
    var target = ($input.data("hidden-target") || "").toString();
    if (target) {
      var $target = $(target);
      if ($target.length) return $target;
    }

    var $closest = $input.closest(".hmpcv2-autocomplete-wrap");
    if ($closest.length) return $closest.find("input[type=hidden]").first();

    return $input.closest("div").find("input[type=hidden]").first();
  }

  function bindAutocomplete($input) {
    $input.autocomplete({
      minLength: 2,
      delay: 150,
      source: function (request, response) {
        $.post(HMPCv2Admin.ajax_url, {
          action: "hmpcv2_search_posts",
          nonce: HMPCv2Admin.nonce,
          q: request.term,
          lang: $input.data("lang") || ""
        }).done(function (res) {
          if (res && res.success && res.data && res.data.items) {
            response(res.data.items);
          } else {
            response([]);
          }
        }).fail(function () {
          response([]);
        });
      },
      select: function (event, ui) {
        // nearest hidden field is the next input[type=hidden] or same block
        var $hidden = getHiddenField($input);
        if ($hidden.length) $hidden.val(ui.item.id);
      },
      change: function () {
        // If user edits text manually, clear hidden value
        var $hidden = getHiddenField($input);
        if ($hidden.length && !$input.val()) $hidden.val("");
      }
    });
  }

  // Metabox inputs (post edit screen)
  $(".hmpcv2-post-search").each(function () {
    bindAutocomplete($(this));
  });

  // Style: Save
  $(document).on("click", "#hmpcv2-style-save", function () {
    $.post(HMPCv2Admin.ajax_url, {
      action: "hmpcv2_style_save",
      nonce: HMPCv2Admin.nonce,
      switcher_z: $("#hmpcv2-style-z").val() || 99999,
      switcher_bg: $("#hmpcv2-style-bg").val() || "",
      switcher_color: $("#hmpcv2-style-color").val() || "",
      force_on_hero: $("#hmpcv2-style-force").is(":checked") ? 1 : 0
    }).done(function (res) {
      if (!res || !res.success) return alert("Style save failed.");
      alert("Style saved.");
    }).fail(function () {
      alert("Style save failed.");
    });
  });

  // Dashboard: create missing translation drafts
  $(document).on("click", ".hmpcv2-create-missing", function () {
    var $btn = $(this);
    var sourceId = parseInt($btn.data("source"), 10) || 0;
    var lang = ($btn.data("lang") || "").toString();

    if (!sourceId || !lang) return;

    $btn.prop("disabled", true).text("Creating…");

    $.post(HMPCv2Admin.ajax_url, {
      action: "hmpcv2_create_translation",
      nonce: HMPCv2Admin.nonce,
      source_id: sourceId,
      target_lang: lang
    }).done(function (res) {
      if (res && res.success && res.data && res.data.edit_url) {
        $btn.text("Open " + lang.toUpperCase()).removeClass("hmpcv2-create-missing").addClass("hmpcv2-open-created");
        $btn.off("click").on("click", function () {
          window.location.href = res.data.edit_url;
        });
      } else {
        $btn.prop("disabled", false).text("Create " + lang.toUpperCase());
        alert("Create failed.");
      }
    }).fail(function () {
      $btn.prop("disabled", false).text("Create " + lang.toUpperCase());
      alert("Create failed.");
    });
  });

  // Complete tab autocomplete (bind + hidden field)
  if ($("#hmpcv2-complete-q").length) {
    $("#hmpcv2-complete-q").autocomplete({
      minLength: 2,
      delay: 150,
      source: function (request, response) {
        $.post(HMPCv2Admin.ajax_url, {
          action: "hmpcv2_search_posts",
          nonce: HMPCv2Admin.nonce,
          q: request.term,
          lang: ""
        }).done(function (res) {
          if (res && res.success && res.data && res.data.items) response(res.data.items);
          else response([]);
        }).fail(function () {
          response([]);
        });
      },
      select: function (event, ui) {
        $("#hmpcv2-complete-id").val(ui.item.id || "");
      },
      change: function () {
        if (!$("#hmpcv2-complete-q").val()) $("#hmpcv2-complete-id").val("");
      }
    });
  }

  // Complete Page: Load
  $(document).on("click", "#hmpcv2-complete-load", function () {
    var sourceId = parseInt($("#hmpcv2-complete-id").val(), 10) || 0;
    var lang = ($("#hmpcv2-complete-lang").val() || "").toString();

    if (!sourceId) return alert("Select content first.");

    $.post(HMPCv2Admin.ajax_url, {
      action: "hmpcv2_complete_load",
      nonce: HMPCv2Admin.nonce,
      source_id: sourceId,
      lang: lang
    }).done(function (res) {
      if (!res || !res.success || !res.data) return alert("Load failed.");

      $("#hmpcv2-complete-editor").show();
      $("#hmpcv2-complete-editor").data("target-id", res.data.target_id);

      $("#hmpcv2-c-title").val(res.data.title || "");
      $("#hmpcv2-c-slug").val(res.data.slug || "");
      $("#hmpcv2-c-excerpt").val(res.data.excerpt || "");
      $("#hmpcv2-c-content").val(res.data.content || "");

      if (res.data.edit_url) {
        $("#hmpcv2-complete-editlink").attr("href", res.data.edit_url).show();
      }
    }).fail(function () {
      alert("Load failed.");
    });
  });

  // Complete Page: Save
  $(document).on("click", "#hmpcv2-complete-save", function () {
    var targetId = parseInt($("#hmpcv2-complete-editor").data("target-id"), 10) || 0;
    if (!targetId) return alert("No target loaded.");

    $.post(HMPCv2Admin.ajax_url, {
      action: "hmpcv2_complete_save",
      nonce: HMPCv2Admin.nonce,
      target_id: targetId,
      title: $("#hmpcv2-c-title").val() || "",
      slug: $("#hmpcv2-c-slug").val() || "",
      excerpt: $("#hmpcv2-c-excerpt").val() || "",
      content: $("#hmpcv2-c-content").val() || ""
    }).done(function (res) {
      if (!res || !res.success) return alert("Save failed.");
      alert("Saved.");
      if (res.data && res.data.edit_url) $("#hmpcv2-complete-editlink").attr("href", res.data.edit_url).show();
    }).fail(function () {
      alert("Save failed.");
    });
  });

  // ===== Complete Page (Pages list + Load More) =====
  var HMPCv2Complete = {
    page: 1,
    busy: false,
    hasMore: true,
    enabled: [],
    def: "",
    reset: function () {
      this.page = 1;
      this.hasMore = true;
      $("#hmpcv2-complete-list").empty();
      $("#hmpcv2-complete-loadmore").hide();
    },
    setBusy: function (on) {
      this.busy = !!on;
      $("#hmpcv2-complete-loading").toggle(!!on);
    },
    fetch: function (append) {
      if (this.busy) return;
      if (!this.hasMore && append) return;

      this.setBusy(true);

      $.post(HMPCv2Admin.ajax_url, {
        action: "hmpcv2_list_pages",
        nonce: HMPCv2Admin.nonce,
        page: this.page
      }).done(function (res) {
        if (!res || !res.success || !res.data) return;

        HMPCv2Complete.enabled = res.data.enabled_langs || [];
        HMPCv2Complete.def = res.data.default_lang || "";

        var items = res.data.items || [];
        if (!append) $("#hmpcv2-complete-list").empty();

        items.forEach(function (it) {
          $("#hmpcv2-complete-list").append(HMPCv2Complete.renderRow(it));
        });

        HMPCv2Complete.hasMore = !!res.data.has_more;
        $("#hmpcv2-complete-loadmore").toggle(HMPCv2Complete.hasMore);

        if (HMPCv2Complete.hasMore) HMPCv2Complete.page = (res.data.page || HMPCv2Complete.page) + 1;
      }).fail(function () {
        // silent
      }).always(function () {
        HMPCv2Complete.setBusy(false);
      });
    },
    renderRow: function (it) {
      var id = it.id;
      var title = it.title || "(no title)";
      var status = (it.status || "").toString();
      var group = it.group || {};
      var map = group.map || {};
      var groupId = group.group || "";

      var pills = "";
      (HMPCv2Complete.enabled || []).forEach(function (code) {
        var has = !!map[code];
        pills += '<span class="hmpcv2-pill ' + (has ? "ok" : "miss") + '">' + code.toUpperCase() + "</span>";
      });

      // actions: mimic Suggested tab style
      var actions = '<div class="hmpcv2-actions" style="margin-top:6px">';
      // ensure group button if missing
      if (!groupId) {
        actions += '<button type="button" class="button button-small hmpcv2-create-group" data-source="' + id + '">Create group</button> ';
      }

      (HMPCv2Complete.enabled || []).forEach(function (code) {
        if (map[code]) {
          var editUrl = it.edit_urls && it.edit_urls[code] ? it.edit_urls[code] : "";
          if (editUrl) {
            actions += '<a class="button button-small" href="' + editUrl + '">Edit ' + code.toUpperCase() + "</a> ";
          } else {
            actions += '<span class="hmpcv2-small">(' + code.toUpperCase() + ")</span> ";
          }
        } else {
          actions += '<button type="button" class="button button-small hmpcv2-create-translation" data-lang="' + code + '" data-source="' + id + '">Create ' + code.toUpperCase() + "</button> ";
        }
      });
      actions += "</div>";

      var html = ""
        + '<div class="hmpcv2-card" data-page-id="' + id + '">'
        + '<div class="hmpcv2-flex">'
        + '<div style="flex:2">'
        + "<strong>" + escapeHtml(title) + "</strong> <span class=\"hmpcv2-small\">#" + id + " — " + escapeHtml(status) + "</span>"
        + "</div>"
        + '<div style="flex:3">'
        + "<div>" + pills + "</div>"
        + actions
        + "</div>"
        + "</div>"
        + "</div>";

      return html;
    }
  };

  function escapeHtml(str) {
    str = (str || "").toString();
    return str.replace(/[&<>\"']/g, function (m) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#039;" })[m];
    });
  }

  // When user opens Complete Page tab, auto-load first page once
  var completeLoadedOnce = false;

  $(document).on("click", ".hmpcv2-tabs .nav-tab[data-tab=\"complete\"]", function () {
    if (completeLoadedOnce) return;
    completeLoadedOnce = true;
    HMPCv2Complete.reset();
    HMPCv2Complete.fetch(false);
  });

  // Refresh list button
  $(document).on("click", "#hmpcv2-complete-refresh", function () {
    completeLoadedOnce = true;
    HMPCv2Complete.reset();
    HMPCv2Complete.fetch(false);
  });

  // Load more
  $(document).on("click", "#hmpcv2-complete-loadmore", function () {
    HMPCv2Complete.fetch(true);
  });
});
