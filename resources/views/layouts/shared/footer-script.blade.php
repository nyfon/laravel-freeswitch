<!-- bundle -->
<script src="{{asset('assets/js/vendor.js')}}"></script>
@yield('script')
<script src="{{asset('assets/js/app.min.js')}}"></script>
@yield('script-bottom')

<script>
    $(document).ready(function() {

        // Domain search
        $("#domainSearchInput").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#domainSearchList *").filter(function() {
                $(this).parent('.listgroup').toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });

        //CallerID single page. 
        //uncheck all of the checkboxes, apart from the one checked
        $('input.callerIdCheckbox').on('change', function() {
            var id = $(this).val();
            var checkbox = $(this);
            var url = '{{ route("updateCallerID", ":id") }}';
            url = url.replace(':id', id);
            $.ajax({
                type : "POST",
                url : url,
                checkbox : $(this),
                headers: {
                    'X-CSRF-Token': '{{ csrf_token() }}',
                },
            })
            .done(function(response) { 
                if (response.error){
                    checkbox.prop('checked', false);
                } else {
                    $('input.callerIdCheckbox').not(checkbox).prop('checked', false);
                }
            })
            .fail(function (response){
                //
            });

        });

        // App Provisioning page
        // Change Provision button status to enabled when at least one organization is selected
        $('input.appCompanyCheckbox').on('change', function() {
            //Uncheck other checkboxes
            $('input.appCompanyCheckbox').not($(this)).prop('checked', false);

            //Toggle the status of Provision button
            var checkBoxes = $('input.appCompanyCheckbox');
            $('#appProvisionButton').toggleClass('disabled', checkBoxes.filter(':checked').length < 1);

            //Prefill the form in the modal with selected values
            $('#organization_name').val($.trim($(this).closest("tr").find('td:eq(1)').text()));
            $('#organization_domain').val($.trim($(this).closest("tr").find('td:eq(1)').text()).toLowerCase().replace(/ /g,'').replace(/[^\w-]+/g,''));
            $('#organization_uuid').val($.trim($(this).val()));
            $('#connection_domain').val($.trim($(this).closest("tr").find('td:eq(2)').text()));
            $('#connection_name').val($.trim($(this).closest("tr").find('td:eq(1)').text()));
            $('#connection_organization_uuid').val($.trim($(this).val()));
        });

        // App Provisioning page
        // Provision new organization
        $('#createOrganizationForm').on('submit', function(e) {
            e.preventDefault();
            //Change button to spinner
            $("#appProvisionNextButton").html('');
            $("#appProvisionNextButton").append('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Loading...');
            $("#appProvisionNextButton").prop( "disabled", true );

            //Hide error message
            $("#appOrganizationError").find("ul").html('');
            $("#appOrganizationError").css('display','none');

            var url = '{{ route("appsCreateOrganization") }}';
 
            $.ajax({
                type : "POST",
                url : url,
                data: $(this).serialize(),
                headers: {
                    'X-CSRF-Token': '{{ csrf_token() }}',
                },
            })
            .done(function(response) {
                // remove the spinner and change button to default
                $("#appProvisionNextButton").html('');
                $("#appProvisionNextButton").append('Next');
                $("#appProvisionNextButton").prop( "disabled", false );

                if (response.error){
                    $("#appOrganizationError").find("ul").html('');
                    $("#appOrganizationError").css('display','block');
                    $("#appOrganizationError").find("ul").append('<li>'+response.message+'</li>');
                    
                 } else {
                    //Switch to the next tab
                    $('a[href*="connection-b2"] span').trigger("click");
                    // Assign Org ID to a hidden input
                    $("#org_id").val(response.org_id);
                }
            })
            .fail(function (response){
                //
            });
        });


        // App Provisioning page
        // Provision new Connection
        $('#createConnectionForm').on('submit', function(e) {
            e.preventDefault();
            //Change button to spinner
            $("#appConnectionNextButton").html('');
            $("#appConnectionNextButton").append('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Loading...');
            $("#appConnectionNextButton").prop( "disabled", true );

            //Hide error message
            $("#appConnectionError").find("ul").html('');
            $("#appConnectionError").css('display','none');

            var url = '{{ route("appsCreateConnection") }}';

            $.ajax({
                type : "POST",
                url : url,
                data: $(this).serialize(),
                headers: {
                    'X-CSRF-Token': '{{ csrf_token() }}',
                },
            })
            .done(function(response) {
                // remove the spinner and change button to default
                $("#appConnectionNextButton").html('');
                $("#appConnectionNextButton").append('Next');
                $("#appConnectionNextButton").prop( "disabled", false );

                if (response.error){
                    $("#appConnectionError").find("ul").html('');
                    $("#appConnectionError").css('display','block');
                    $("#appConnectionError").find("ul").append('<li>'+response.message+'</li>');
                    
                 } else {
                    //Switch to the next tab
                    $('a[href*="result-b2"] span').trigger("click");

                }
            })
            .fail(function (response){
                //
            });
        });

        // https://stackoverflow.com/a/43247613/10697374
        // $('#myModal').on('show.bs.modal', function(e) {  
        //     var getIdFromRow = $(e.relatedTarget).data('id');
        //     $("#buyghc").val(getIdFromRow);
        // });

        // $('input.callerIdCheckbox').on('change', function() {
        //     var id = $(this).val();
        //     var checkbox = $(this);
        //     var url = '{{ route("updateCallerID", ":id") }}';
        //     url = url.replace(':id', id);
        //     $.ajax({
        //         type : "POST",
        //         url : url,
        //         checkbox : $(this),
        //         headers: {
        //             'X-CSRF-Token': '{{ csrf_token() }}',
        //         },
        //     })
        //     .done(function(response) { 
        //         if (response.error){
        //             checkbox.prop('checked', false);
        //         } else {
        //             $('input.callerIdCheckbox').not(checkbox).prop('checked', false);
        //         }
        //     })
        //     .fail(function (response){
        //         //
        //     });

        // });

    });
</script>