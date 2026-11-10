<!-- Zulu Clock, followed by the user's local time when they chose a time zone -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        var localZone = @json(\App\Support\DisplayTime::isZulu() ? null : \App\Support\DisplayTime::zone());
        var localSuffix = @json(\App\Support\DisplayTime::suffix(now()));
        var localFormat = localZone ? new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: localZone }) : null;

        setInterval(function (){
            var currentdate = new Date()
            var datetime = ('0'+currentdate.getUTCHours()).substr(-2,2) + ":" + ('0'+currentdate.getUTCMinutes()).substr(-2,2) + 'z'
            if (localFormat) {
                datetime += ' · ' + localFormat.format(currentdate) + localSuffix
            }
            document.querySelector('.zulu-clock').innerHTML = datetime
        }, 1000)
    })
</script>
