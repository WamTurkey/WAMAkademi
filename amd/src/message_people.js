define([
    'jquery',
    'core/templates',
    'core/notification',
    'core_message/message_drawer_helper'
], function($, Templates, Notification, MessageDrawerHelper) {

    const SELECTORS = {
        ADMINS: '[data-region="view-overview-favourites"]',
        TEACHERS: '[data-region="view-overview-group-messages"]',
        FRIENDS: '[data-region="view-overview-messages"]'
    };

    const TEMPLATE = 'core_message/message_drawer_conversations_list';

    const getContentContainer = function(root) {
        return root.find('[data-region="content-container"]');
    };

    const getPlaceholderContainer = function(root) {
        return root.find('[data-region="placeholder-container"]');
    };

    const getEmptyContainer = function(root) {
        return root.find('[data-region="empty-message-container"]');
    };

    const getCountContainer = function(root) {
        return root.find('[data-region="section-total-count"]');
    };

    const getCountWrapper = function(root) {
        return root.find('[data-region="section-total-count-container"]');
    };

    const updateSection = async function(root, users) {
    const content = getContentContainer(root);
    const placeholder = getPlaceholderContainer(root);
    const empty = getEmptyContainer(root);
    const count = getCountContainer(root);
    const countWrapper = getCountWrapper(root);

    placeholder.addClass('hidden');

    count.text(users.length);
    countWrapper.removeClass('hidden');

    if (!users.length) {
        content
            .empty()
            .addClass('hidden');

        empty.removeClass('hidden');
        return;
    }

    try {
        const html = await Templates.render(TEMPLATE, {
            conversations: users
        });

        empty.addClass('hidden');

        content
            .html(html)
            .removeClass('hidden');

        // Moodle native lazy-loader bazen görünürlüğü tekrar değiştiriyor.
        // Bu yüzden kısa aralıklarla bizim durumu tekrar sabitliyoruz.
        setTimeout(function() {
            empty.addClass('hidden');
            content.removeClass('hidden');
        }, 150);

        setTimeout(function() {
            empty.addClass('hidden');
            content.removeClass('hidden');
        }, 500);

    } catch (error) {
        Notification.exception(error);
    }
};

    const load = async function() {
        try {
            const response = await fetch(
                M.cfg.wwwroot + '/local/wamakademi/get_message_people.php',
                {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            if (!response.ok) {
                throw new Error('WAM kişi listesi alınamadı.');
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error('WAM kişi listesi başarısız döndü.');
            }

            await updateSection(
                $(SELECTORS.ADMINS),
                data.admins || []
            );

            await updateSection(
                $(SELECTORS.TEACHERS),
                data.teachers || []
            );

            await updateSection(
                $(SELECTORS.FRIENDS),
                data.friends || []
            );

        } catch (error) {
            Notification.exception(error);
        }
    };
return {
    init: function() {
        load();

        $(document).on('click', '[data-region="popover-region-messages-toggle"]', function() {
            setTimeout(function() {
                load();
            }, 400);
        });

        $(document).on(
            'click',
            '[data-region="view-overview-favourites"] [data-user-id], ' +
            '[data-region="view-overview-group-messages"] [data-user-id], ' +
            '[data-region="view-overview-messages"] [data-user-id]',
            function(e) {
                e.preventDefault();

var userId = parseInt($(this).attr('data-user-id'), 10);
var userName = $(this).find('.name').first().text().trim();

if (!userName) {
    userName = $(this).text().trim();
}

if (!userId) {
    return;
}

MessageDrawerHelper.createConversationWithUser({
    userid: userId,
    username: userName
});

            }
        );

        setTimeout(function() {
            load();
        }, 800);
    }
};
});
