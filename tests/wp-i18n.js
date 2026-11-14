// WordPress provides these functions through the wp-i18n script dependency.
module.exports = function installI18n(context) {
    const wp = context.window.wp || context.wp || {};
    wp.i18n = wp.i18n || {
        __(text) { return text; },
        _n(single, plural, number) { return Number(number) === 1 ? single : plural; },
        sprintf(format, ...args) {
            let index = 0;
            return format.replace(/%(?:(\d+)\$)?([sd])/g, (match, position) =>
                String(args[position ? Number(position) - 1 : index++]));
        }
    };
    context.window.wp = wp;
    context.wp = wp;
};
