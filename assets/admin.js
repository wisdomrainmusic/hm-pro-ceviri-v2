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
});
