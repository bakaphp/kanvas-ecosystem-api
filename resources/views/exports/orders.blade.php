<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte de Órdenes</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .logos-section {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .logo {
            max-height: 50px;
            max-width: 120px;
            object-fit: contain;
            margin: 5px;
        }
        .company-info {
            margin: 15px 0;
        }
        .company-info h1 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 24px;
        }
        .company-address {
            font-weight: bold;
            color: #555;
            margin: 10px 0;
        }
        .export-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #007bff;
        }
        .export-info p {
            margin: 5px 0;
            font-size: 11px;
        }
        .export-info strong {
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 10px;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #333;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logos-section">
            @if(isset($orders['header_info']['logos']))
                @foreach($orders['header_info']['logos'] as $logoName => $logoPath)
                    @if(file_exists($logoPath))
                        <img src="{{ $logoPath }}" alt="{{ $logoName }}" class="logo">
                    @else
                        <div style="border: 1px solid #ccc; padding: 10px; margin: 5px; font-size: 10px;">
                            {{ $logoName }}
                        </div>
                    @endif
                @endforeach
            @endif
        </div>
        
        <div class="company-info">
            <h1>REPORTE DE ÓRDENES</h1>
            <div class="company-address">
                {{ $orders['header_info']['company_address'] ?? 'CENTRO RETENCIÓN VEHICULAR AV. 27 DE FEBRERO' }}
            </div>
        </div>
    </div>
    
    <div class="export-info">
        <p><strong>Fecha de exportación:</strong> {{ $orders['header_info']['export_date'] ?? date('d/m/Y H:i:s') }}</p>
        <p><strong>Rango de fechas aplicado:</strong> {{ $orders['header_info']['date_range'] ?? 'No especificado' }}</p>
        <p><strong>Estados seleccionados:</strong> {{ $orders['header_info']['status_filter'] ?? 'Todos' }}</p>
    </div>

    <table>
        @if(isset($orders['orders']) && count($orders['orders']) > 0)
            <thead>
                <tr>
                    @foreach($orders['headers'] as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($orders['orders'] as $order)
                    <tr>
                        @foreach($order as $value)
                            <td>{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        @else
            <tr>
                <td colspan="100%" style="text-align: center; padding: 20px;">No se encontraron órdenes</td>
            </tr>
        @endif
    </table>
    
    <div class="footer">
        <p>Este reporte contiene {{ isset($orders['orders']) ? count($orders['orders']) : 0 }} registros</p>
        <p>Generado el {{ date('d/m/Y') }} a las {{ date('H:i:s') }}</p>
    </div>
</body>
</html>