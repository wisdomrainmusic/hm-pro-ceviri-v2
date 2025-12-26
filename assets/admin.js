jQuery(function ($) {
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
        var $hidden = $input.closest("div").find("input[type=hidden]").first();
        if ($hidden.length) $hidden.val(ui.item.id);
      },
      change: function () {
        // If user edits text manually, clear hidden value
        var $hidden = $input.closest("div").find("input[type=hidden]").first();
        if ($hidden.length && !$input.val()) $hidden.val("");
      }
    });
  }

  // Metabox inputs (post edit screen)
  $(".hmpcv2-post-search").each(function () {
    bindAutocomplete($(this));
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
});
